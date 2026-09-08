<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PropertySearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d'],
            'guests' => ['required', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $checkIn = $this->input('check_in');
                $checkOut = $this->input('check_out');

                if (is_string($checkIn) && is_string($checkOut) && $checkOut <= $checkIn) {
                    $validator->errors()->add(
                        'check_out',
                        'The check out date must be after the check in date.',
                    );
                }
            },
        ];
    }
}
