<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DealFiProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'fi_product_id' => $this->fi_product_id,
            'price'         => round($this->price / 100, 2),
            'fi_product'    => new FiProductResource($this->whenLoaded('fiProduct')),
        ];
    }
}
