<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'name'               => $this->name,
            'email'              => $this->email,
            'role'               => $this->role,
            'phone'              => $this->phone,
            'avatar_url'         => $this->avatar_url,
            'email_verified_at'  => $this->email_verified_at?->toIso8601String(),
            'dealer_id'          => $this->dealer_id,
            'dealer'             => $this->whenLoaded('dealer', fn () => [
                'id'            => $this->dealer->id,
                'name'          => $this->dealer->name,
                'slug'          => $this->dealer->slug,
                'subdomain'     => $this->dealer->subdomain,
                'logo_url'      => $this->dealer->logo_url,
                'primary_color' => $this->dealer->primary_color,
                'feature_flags' => $this->dealer->feature_flags,
            ]),
            'created_at'         => $this->created_at->toIso8601String(),
        ];
    }
}
