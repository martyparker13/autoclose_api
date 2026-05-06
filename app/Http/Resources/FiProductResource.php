<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FiProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'dealer_id'   => $this->dealer_id,
            'name'        => $this->name,
            'type'        => $this->type,
            'provider'    => $this->provider,
            'description' => $this->description,
            'price'       => round($this->price / 100, 2),
            'term_months' => $this->term_months,
            'is_active'   => $this->is_active,
        ];
    }
}
