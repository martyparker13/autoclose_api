<?php

namespace App\Http\Requests\Delivery;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeliveryAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type'                => ['required', 'in:home_delivery,lot_pickup'],
            'scheduled_at'        => ['required', 'date', 'after:now'],
            'address'             => ['required_if:type,home_delivery', 'nullable', 'array'],
            'address.street'      => ['required_if:type,home_delivery', 'nullable', 'string', 'max:200'],
            'address.city'        => ['required_if:type,home_delivery', 'nullable', 'string', 'max:100'],
            'address.state'       => ['required_if:type,home_delivery', 'nullable', 'string', 'size:2'],
            'address.zip'         => ['required_if:type,home_delivery', 'nullable', 'string', 'max:10'],
            'notes'               => ['nullable', 'string', 'max:1000'],
        ];
    }
}
