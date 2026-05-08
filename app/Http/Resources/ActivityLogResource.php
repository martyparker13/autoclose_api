<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ActivityLog
 */
class ActivityLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'event'      => $this->event,
            'model_type' => $this->model_type ? class_basename($this->model_type) : null,
            'model_id'   => $this->model_id,
            'user'       => $this->whenLoaded('user', fn () => $this->user ? [
                'id'   => $this->user->id,
                'name' => $this->user->name,
                'role' => $this->user->role,
            ] : null),
            'dealer'     => $this->whenLoaded('dealer', fn () => $this->dealer ? [
                'id'   => $this->dealer->id,
                'name' => $this->dealer->name,
            ] : null),
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
