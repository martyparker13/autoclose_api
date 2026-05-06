<?php

namespace App\Http\Requests\Deal;

use Illuminate\Foundation\Http\FormRequest;

class SyncFiProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'products'                    => ['required', 'array'],
            'products.*.fi_product_id'    => ['required', 'integer', 'exists:fi_products,id'],
            'products.*.price'            => ['nullable', 'integer', 'min:0'],
        ];
    }
}
