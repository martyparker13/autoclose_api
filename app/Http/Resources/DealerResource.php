<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Dealer
 */
class DealerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'name'                => $this->name,
            'slug'                => $this->slug,
            'subdomain'           => $this->subdomain,
            'custom_domain'       => $this->custom_domain,
            'logo_url'            => $this->logo_url,
            'primary_color'       => $this->primary_color,
            'phone'               => $this->phone,
            'email'               => $this->email,
            'address'             => $this->address,
            'city'                => $this->city,
            'state'               => $this->state,
            'zip'                 => $this->zip,
            'license_number'      => $this->license_number,
            'dms_provider'        => $this->dms_provider,
            'subscription_plan'   => $this->subscription_plan,
            'subscription_status' => $this->subscription_status,
            'is_active'           => $this->is_active,
            'feature_flags'       => $this->feature_flags ?? [],
            'google_review_url'   => $this->google_review_url,
            'integrations'        => [
                'dealertrack' => [
                    'connected' => ! empty($this->dealertrack_credentials),
                ],
                'routeone' => [
                    'connected' => ! empty($this->routeone_credentials),
                ],
            ],
            'deleted_at'          => $this->deleted_at?->toIso8601String(),
            'created_at'          => $this->created_at->toIso8601String(),
            'updated_at'          => $this->updated_at->toIso8601String(),
        ];
    }
}
