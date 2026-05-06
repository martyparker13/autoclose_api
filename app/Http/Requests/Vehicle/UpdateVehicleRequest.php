<?php

namespace App\Http\Requests\Vehicle;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'vin'            => ['sometimes', 'nullable', 'string', 'size:17'],
            'stock_number'   => ['sometimes', 'nullable', 'string', 'max:50'],
            'year'           => ['sometimes', 'required', 'integer', 'min:1980', 'max:2030'],
            'make'           => ['sometimes', 'required', 'string', 'max:100'],
            'model'          => ['sometimes', 'required', 'string', 'max:100'],
            'trim'           => ['sometimes', 'nullable', 'string', 'max:100'],
            'body_style'     => ['sometimes', 'nullable', 'string', 'max:50'],
            'exterior_color' => ['sometimes', 'nullable', 'string', 'max:100'],
            'interior_color' => ['sometimes', 'nullable', 'string', 'max:100'],
            'mileage'        => ['sometimes', 'required', 'integer', 'min:0'],
            'condition'      => ['sometimes', 'required', Rule::in(['new', 'used', 'certified'])],
            'price'          => ['sometimes', 'required', 'integer', 'min:0'],
            'msrp'           => ['sometimes', 'nullable', 'integer', 'min:0'],
            'internet_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'transmission'   => ['sometimes', 'nullable', 'string', 'max:50'],
            'engine'         => ['sometimes', 'nullable', 'string', 'max:100'],
            'drivetrain'     => ['sometimes', 'nullable', 'string', 'max:20'],
            'fuel_type'      => ['sometimes', 'nullable', 'string', 'max:50'],
            'doors'          => ['sometimes', 'nullable', 'integer', 'min:1', 'max:6'],
            'cylinders'      => ['sometimes', 'nullable', 'integer', 'min:0', 'max:16'],
            'status'         => ['sometimes', 'nullable', Rule::in(['available', 'pending', 'sold', 'hold'])],
            'description'    => ['sometimes', 'nullable', 'string'],
            'carfax_url'     => ['sometimes', 'nullable', 'url', 'max:500'],
        ];
    }
}
