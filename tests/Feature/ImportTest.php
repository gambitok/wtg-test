<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_import_is_created_and_dispatched_to_the_queue(): void
    {
        Queue::fake();
        $supplier = Supplier::create([
            'code' => 'supplier-a',
            'name' => 'Supplier A',
        ]);

        $response = $this->postJson('/api/imports', $this->payload());
        $importId = $response->json('data.id');

        $response
            ->assertAccepted()
            ->assertJsonPath('data.id', $importId)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total_offers', 1);

        $this->assertDatabaseHas('imports', [
            'supplier_id' => $supplier->id,
            'external_import_id' => 'import-2026-09-08-001',
            'status' => 'pending',
        ], 'mysql');

        Queue::assertPushed(ProcessImportJob::class, function (ProcessImportJob $job) use ($importId): bool {
            return $job->importId === $importId;
        });
    }

    public function test_the_same_import_is_idempotent(): void
    {
        Queue::fake();
        Supplier::create([
            'code' => 'supplier-a',
            'name' => 'Supplier A',
        ]);

        $firstResponse = $this->postJson('/api/imports', $this->payload());
        $secondResponse = $this->postJson('/api/imports', $this->payload());

        $firstResponse->assertAccepted();
        $secondResponse
            ->assertAccepted()
            ->assertJsonPath('data.id', $firstResponse->json('data.id'));

        $this->assertDatabaseCount('imports', 1, 'mysql');
        Queue::assertPushedTimes(ProcessImportJob::class, 1);
    }

    public function test_import_status_can_be_retrieved(): void
    {
        Queue::fake();
        Supplier::create([
            'code' => 'supplier-a',
            'name' => 'Supplier A',
        ]);

        $createResponse = $this->postJson('/api/imports', $this->payload());
        $importId = $createResponse->json('data.id');

        $this->getJson("/api/imports/$importId")
            ->assertOk()
            ->assertJsonPath('data.id', $importId)
            ->assertJsonPath('data.supplier', 'supplier-a')
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_the_job_persists_properties_and_offers(): void
    {
        Queue::fake();
        $supplier = Supplier::create([
            'code' => 'supplier-a',
            'name' => 'Supplier A',
        ]);

        $response = $this->postJson('/api/imports', $this->payload());
        $importId = $response->json('data.id');

        (new ProcessImportJob($importId))->handle();

        $this->assertDatabaseHas('properties', [
            'code' => 'BCN-0001',
            'city' => 'Barcelona',
        ], 'mysql');
        $this->assertDatabaseHas('offers', [
            'supplier_id' => $supplier->id,
            'external_id' => 'offer-a-10001',
            'price' => 72500,
        ], 'mysql');
        $this->assertDatabaseHas('imports', [
            'id' => $importId,
            'status' => 'completed',
            'processed_offers' => 1,
        ], 'mysql');
    }

    public function test_an_existing_offer_is_updated_by_a_later_import(): void
    {
        Queue::fake();
        Supplier::create([
            'code' => 'supplier-a',
            'name' => 'Supplier A',
        ]);

        $firstResponse = $this->postJson('/api/imports', $this->payload());
        (new ProcessImportJob($firstResponse->json('data.id')))->handle();

        $secondPayload = $this->payload([
            'external_import_id' => 'import-2026-09-08-002',
            'offers' => [
                [
                    'external_id' => 'offer-a-10001',
                    'property' => [
                        'code' => 'BCN-0001',
                        'name' => 'Updated Apartment',
                        'city' => 'Barcelona',
                    ],
                    'check_in' => '2026-10-10',
                    'check_out' => '2026-10-15',
                    'max_guests' => 4,
                    'price' => 70000,
                    'currency' => 'EUR',
                    'available_units' => 1,
                    'expires_at' => '2026-09-11T23:59:59Z',
                ],
            ],
        ]);

        $secondResponse = $this->postJson('/api/imports', $secondPayload);
        (new ProcessImportJob($secondResponse->json('data.id')))->handle();

        $this->assertDatabaseCount('offers', 1, 'mysql');
        $this->assertDatabaseHas('offers', [
            'external_id' => 'offer-a-10001',
            'price' => 70000,
            'import_id' => $secondResponse->json('data.id'),
        ], 'mysql');
        $this->assertSame('Updated Apartment', Property::query()->firstOrFail()->name);
    }

    public function test_invalid_import_data_is_rejected(): void
    {
        Supplier::create([
            'code' => 'supplier-a',
            'name' => 'Supplier A',
        ]);

        $response = $this->postJson('/api/imports', [
            'supplier' => 'supplier-a',
            'external_import_id' => 'import-invalid',
            'sent_at' => '2026-09-08T10:00:00Z',
            'offers' => [[
                'external_id' => 'offer-invalid',
                'property' => [
                    'code' => 'BCN-0002',
                    'name' => 'Invalid Apartment',
                    'city' => 'Barcelona',
                ],
                'check_in' => '2026-10-15',
                'check_out' => '2026-10-10',
                'max_guests' => 4,
                'price' => 72500,
                'currency' => 'EUR',
                'available_units' => 1,
                'expires_at' => '2026-09-10T23:59:59Z',
            ]],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('offers.0.check_out');
    }

    public function test_a_failed_job_marks_the_import_as_failed(): void
    {
        $import = Import::create([
            'supplier_id' => Supplier::create([
                'code' => 'supplier-a',
                'name' => 'Supplier A',
            ])->id,
            'external_import_id' => 'import-failed',
            'sent_at' => now(),
            'status' => ImportStatus::Pending,
            'total_offers' => 1,
            'processed_offers' => 0,
            'payload' => [
                'offers' => [[
                    'external_id' => 'offer-invalid',
                    'property' => [],
                ]],
            ],
        ]);

        $this->expectException(\ErrorException::class);

        try {
            (new ProcessImportJob($import->id))->handle();
        } finally {
            $this->assertDatabaseHas('imports', [
                'id' => $import->id,
                'status' => ImportStatus::Failed->value,
            ], 'mysql');
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'supplier' => 'supplier-a',
            'external_import_id' => 'import-2026-09-08-001',
            'sent_at' => '2026-09-08T10:00:00Z',
            'offers' => [[
                'external_id' => 'offer-a-10001',
                'property' => [
                    'code' => 'BCN-0001',
                    'name' => 'Apartment near Sagrada Familia',
                    'city' => 'Barcelona',
                ],
                'check_in' => '2026-10-10',
                'check_out' => '2026-10-15',
                'max_guests' => 4,
                'price' => 72500,
                'currency' => 'EUR',
                'available_units' => 2,
                'expires_at' => '2026-09-10T23:59:59Z',
            ]],
        ], $overrides);
    }
}
