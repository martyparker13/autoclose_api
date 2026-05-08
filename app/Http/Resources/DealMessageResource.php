<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DealMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'deal_id'    => $this->deal_id,
            'sender_id'  => $this->sender_id,
            'sender'     => [
                'id'   => $this->sender?->id,
                'name' => $this->sender?->name,
                'role' => $this->sender?->role,
            ],
            'body'       => $this->body,
            'read_at'    => $this->read_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
