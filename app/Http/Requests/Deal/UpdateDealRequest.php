<?php

namespace App\Http\Requests\Deal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy enforced in controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sale_price'        => ['sometimes', 'integer', 'min:0'],
            'down_payment'      => ['sometimes', 'integer', 'min:0'],
            'trade_in_value'    => ['sometimes', 'integer', 'min:0'],
            'trade_in_vehicle'  => ['sometimes', 'nullable', 'array'],
            'trade_in_vehicle.year'  => ['required_with:trade_in_vehicle', 'integer', 'min:1980', 'max:2030'],
            'trade_in_vehicle.make'  => ['required_with:trade_in_vehicle', 'string', 'max:100'],
            'trade_in_vehicle.model' => ['required_with:trade_in_vehicle', 'string', 'max:100'],
            'trade_in_vehicle.trim'  => ['nullable', 'string', 'max:100'],
            'trade_in_vehicle.mileage' => ['nullable', 'integer', 'min:0'],
            'trade_in_vehicle.vin'   => ['nullable', 'string', 'size:17'],
            'apr'               => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'term_months'       => ['sometimes', 'nullable', 'integer', Rule::in([12, 24, 36, 48, 60, 72, 84])],
            'lender'            => ['sometimes', 'nullable', 'string', 'max:200'],
            'notes'             => ['sometimes', 'nullable', 'string'],
        ];
    }
}
