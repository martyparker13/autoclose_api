<?php

namespace App\Http\Requests\Vehicle;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Role enforced via middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'vin'            => ['nullable', 'string', 'size:17'],
            'stock_number'   => ['nullable', 'string', 'max:50'],
            'year'           => ['required', 'integer', 'min:1980', 'max:2030'],
            'make'           => ['required', 'string', 'max:100'],
            'model'          => ['required', 'string', 'max:100'],
            'trim'           => ['nullable', 'string', 'max:100'],
            'body_style'     => ['nullable', 'string', 'max:50'],
            'exterior_color' => ['nullable', 'string', 'max:100'],
            'interior_color' => ['nullable', 'string', 'max:100'],
            'mileage'        => ['required', 'integer', 'min:0'],
            'condition'      => ['required', Rule::in(['new', 'used', 'certified'])],
            'price'          => ['required', 'integer', 'min:0'], // in cents
            'msrp'           => ['nullable', 'integer', 'min:0'],
            'internet_price' => ['nullable', 'integer', 'min:0'],
            'transmission'   => ['nullable', 'string', 'max:50'],
            'engine'         => ['nullable', 'string', 'max:100'],
            'drivetrain'     => ['nullable', 'string', 'max:20'],
            'fuel_type'      => ['nullable', 'string', 'max:50'],
            'doors'          => ['nullable', 'integer', 'min:1', 'max:6'],
            'cylinders'      => ['nullable', 'integer', 'min:0', 'max:16'],
            'status'         => ['nullable', Rule::in(['available', 'pending', 'hold'])],
            'description'    => ['nullable', 'string'],
            'carfax_url'     => ['nullable', 'url', 'max:500'],
        ];
    }
}
