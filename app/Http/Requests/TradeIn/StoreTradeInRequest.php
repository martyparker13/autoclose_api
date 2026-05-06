<?php

namespace App\Http\Requests\TradeIn;

use Illuminate\Foundation\Http\FormRequest;

class StoreTradeInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'year'      => ['required', 'integer', 'min:1900', 'max:' . (date('Y') + 1)],
            'make'      => ['required', 'string', 'max:100'],
            'model'     => ['required', 'string', 'max:100'],
            'trim'      => ['nullable', 'string', 'max:100'],
            'mileage'   => ['required', 'integer', 'min:0'],
            'vin'       => ['nullable', 'string', 'size:17'],
            'condition' => ['required', 'in:excellent,good,fair,poor'],
        ];
    }
}
