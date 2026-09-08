<?php

namespace App\Http\Resources;

use App\Enums\ImportStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier' => $this->supplier?->code,
            'external_import_id' => $this->external_import_id,
            'sent_at' => $this->sent_at,
            'status' => $this->status instanceof ImportStatus
                ? $this->status->value
                : $this->status,
            'total_offers' => $this->total_offers,
            'processed_offers' => $this->processed_offers,
            'error' => $this->error,
            'created_at' => $this->created_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
