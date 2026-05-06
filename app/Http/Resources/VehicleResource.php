<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $primaryMedia = $this->whenLoaded('media', function () {
            return $this->media->firstWhere('is_primary', true)?->url
                ?? $this->media->first()?->url;
        });

        return [
            'id'             => $this->id,
            'dealer_id'      => $this->dealer_id,
            'vin'            => $this->vin,
            'stock_number'   => $this->stock_number,
            'year'           => $this->year,
            'make'           => $this->make,
            'model'          => $this->model,
            'trim'           => $this->trim,
            'body_style'     => $this->body_style,
            'exterior_color' => $this->exterior_color,
            'interior_color' => $this->interior_color,
            'mileage'        => $this->mileage,
            'condition'      => $this->condition,
            'price'          => round($this->price / 100, 2),
            'msrp'           => $this->msrp ? round($this->msrp / 100, 2) : null,
            'internet_price' => $this->internet_price ? round($this->internet_price / 100, 2) : null,
            'transmission'   => $this->transmission,
            'engine'         => $this->engine,
            'drivetrain'     => $this->drivetrain,
            'fuel_type'      => $this->fuel_type,
            'doors'          => $this->doors,
            'cylinders'      => $this->cylinders,
            'status'         => $this->status,
            'description'    => $this->description,
            'carfax_url'     => $this->carfax_url,
            'primary_photo'  => $primaryMedia,
            'media'          => VehicleMediaResource::collection($this->whenLoaded('media')),
            'features'       => VehicleFeatureResource::collection($this->whenLoaded('features')),
            'created_at'     => $this->created_at?->toISOString(),
            'updated_at'     => $this->updated_at?->toISOString(),
        ];
    }
}
