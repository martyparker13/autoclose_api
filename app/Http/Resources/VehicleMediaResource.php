<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleMediaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'type'       => $this->type,
            'url'        => $this->url,
            'sort_order' => $this->sort_order,
            'is_primary' => $this->is_primary,
            'label'      => $this->label,
        ];
    }
}
