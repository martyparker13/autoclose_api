<?php

namespace App\Http\Requests\FiProduct;

use Illuminate\Foundation\Http\FormRequest;

class StoreFiProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Role/tenant checked in route middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:200'],
            'type'        => ['required', 'in:warranty,gap,tire_wheel,paint_protection,key_replacement,credit_life,credit_disability'],
            'provider'    => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'cost'        => ['required', 'integer', 'min:0'],
            'price'       => ['required', 'integer', 'min:0'],
            'term_months' => ['nullable', 'integer', 'min:1', 'max:240'],
            'is_active'   => ['sometimes', 'boolean'],
        ];
    }
}
