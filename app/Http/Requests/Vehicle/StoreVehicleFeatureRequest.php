<?php

namespace App\Http\Requests\Vehicle;

use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleFeatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Role enforced via middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'features'               => ['required', 'array', 'min:1'],
            'features.*.feature_name' => ['required', 'string', 'max:200'],
            'features.*.category'    => ['nullable', 'string', 'max:100'],
        ];
    }
}
