<?php

namespace App\Http\Requests\Deal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy enforced in controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'vehicle_id'   => ['required', 'integer', 'exists:vehicles,id'],
            'sale_price'   => ['nullable', 'integer', 'min:0'],
            'down_payment' => ['nullable', 'integer', 'min:0'],
            'source'       => ['nullable', Rule::in(['web', 'mobile', 'in_store'])],
        ];
    }
}
