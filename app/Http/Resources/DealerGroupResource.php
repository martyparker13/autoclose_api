<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\DealerGroup
 */
class DealerGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'slug'          => $this->slug,
            'logo_url'      => $this->logo_url,
            'primary_color' => $this->primary_color,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'is_active'     => $this->is_active,
            'dealers_count' => $this->whenCounted('dealers'),
            'deleted_at'    => $this->deleted_at?->toIso8601String(),
            'created_at'    => $this->created_at->toIso8601String(),
            'updated_at'    => $this->updated_at->toIso8601String(),
        ];
    }
}
