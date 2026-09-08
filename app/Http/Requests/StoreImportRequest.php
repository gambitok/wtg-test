<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier' => ['required', 'string', 'max:100', 'exists:suppliers,code'],
            'external_import_id' => ['required', 'string', 'max:255'],
            'sent_at' => ['required', 'date'],
            'offers' => ['required', 'array'],
            'offers.*.external_id' => ['required', 'string', 'max:255', 'distinct'],
            'offers.*.property' => ['required', 'array'],
            'offers.*.property.code' => ['required', 'string', 'max:100'],
            'offers.*.property.name' => ['required', 'string', 'max:255'],
            'offers.*.property.city' => ['required', 'string', 'max:100'],
            'offers.*.check_in' => ['required', 'date_format:Y-m-d'],
            'offers.*.check_out' => ['required', 'date_format:Y-m-d'],
            'offers.*.max_guests' => ['required', 'integer', 'min:1'],
            'offers.*.price' => ['required', 'integer', 'min:0'],
            'offers.*.currency' => ['required', 'string', 'size:3'],
            'offers.*.available_units' => ['required', 'integer', 'min:0'],
            'offers.*.expires_at' => ['required', 'date'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ($this->input('offers', []) as $index => $offer) {
                    if (! is_array($offer)) {
                        continue;
                    }

                    $checkIn = $offer['check_in'] ?? null;
                    $checkOut = $offer['check_out'] ?? null;

                    if (is_string($checkIn) && is_string($checkOut) && $checkOut <= $checkIn) {
                        $validator->errors()->add(
                            "offers.$index.check_out",
                            'The check out date must be after the check in date.',
                        );
                    }
                }
            },
        ];
    }
}
