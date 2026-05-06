<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryAppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'deal_id'      => $this->deal_id,
            'dealer_id'    => $this->dealer_id,
            'type'         => $this->type,
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'address'      => $this->address,
            'driver_id'    => $this->driver_id,
            'status'       => $this->status,
            'notes'        => $this->notes,
            'created_at'   => $this->created_at?->toISOString(),
            'updated_at'   => $this->updated_at?->toISOString(),
        ];
    }
}
