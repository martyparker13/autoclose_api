<?php

namespace App\Http\Requests\Delivery;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeliveryAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scheduled_at' => ['sometimes', 'date', 'after:now'],
            'status'       => ['sometimes', 'in:scheduled,en_route,completed,cancelled'],
            'driver_id'    => ['nullable', 'integer', 'exists:users,id'],
            'notes'        => ['nullable', 'string', 'max:1000'],
            'address'      => ['sometimes', 'nullable', 'array'],
        ];
    }
}
