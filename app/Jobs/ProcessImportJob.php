<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessImportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $importId) {}

    public function uniqueId(): string
    {
        return (string) $this->importId;
    }

    public function handle(): void
    {
        $import = Import::query()->find($this->importId);

        if (! $import || $import->status === ImportStatus::Completed) {
            return;
        }

        $import->update([
            'status' => ImportStatus::Processing,
            'error' => null,
            'completed_at' => null,
        ]);

        try {
            DB::transaction(function () use ($import): void {
                foreach ($import->payload['offers'] ?? [] as $offerData) {
                    $property = Property::query()->updateOrCreate(
                        ['code' => $offerData['property']['code']],
                        [
                            'name' => $offerData['property']['name'],
                            'city' => $offerData['property']['city'],
                        ],
                    );

                    Offer::query()->updateOrCreate(
                        [
                            'supplier_id' => $import->supplier_id,
                            'external_id' => $offerData['external_id'],
                        ],
                        [
                            'import_id' => $import->id,
                            'property_id' => $property->id,
                            'check_in' => $offerData['check_in'],
                            'check_out' => $offerData['check_out'],
                            'max_guests' => $offerData['max_guests'],
                            'price' => $offerData['price'],
                            'currency' => strtoupper($offerData['currency']),
                            'available_units' => $offerData['available_units'],
                            'expires_at' => $offerData['expires_at'],
                        ],
                    );
                }

                $import->update([
                    'status' => ImportStatus::Completed,
                    'processed_offers' => $import->total_offers,
                    'completed_at' => now(),
                    'error' => null,
                ]);
            });
        } catch (Throwable $exception) {
            $this->markAsFailed($exception);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->markAsFailed($exception);
    }

    private function markAsFailed(?Throwable $exception): void
    {
        Import::query()->whereKey($this->importId)->update([
            'status' => ImportStatus::Failed->value,
            'error' => $exception?->getMessage(),
        ]);
    }
}
