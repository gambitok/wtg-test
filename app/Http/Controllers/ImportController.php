<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreImportRequest;
use App\Http\Resources\ImportResource;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ImportController extends Controller
{
    public function store(StoreImportRequest $request): JsonResponse
    {
        $data = $request->validated();
        $supplier = Supplier::query()->where('code', $data['supplier'])->firstOrFail();

        try {
            $import = DB::transaction(function () use ($data, $supplier): Import {
                $import = Import::create([
                    'supplier_id' => $supplier->id,
                    'external_import_id' => $data['external_import_id'],
                    'sent_at' => $data['sent_at'],
                    'status' => 'pending',
                    'total_offers' => count($data['offers']),
                    'processed_offers' => 0,
                    'payload' => [
                        'offers' => $data['offers'],
                    ],
                ]);

                return $import;
            });

            ProcessImportJob::dispatch($import->id);
        } catch (UniqueConstraintViolationException) {
            $import = Import::query()
                ->where('supplier_id', $supplier->id)
                ->where('external_import_id', $data['external_import_id'])
                ->firstOrFail();
        }

        $import->load('supplier');

        return (new ImportResource($import))
            ->response()
            ->setStatusCode(202);
    }

    public function show(Import $import): ImportResource
    {
        return new ImportResource($import->load('supplier'));
    }
}
