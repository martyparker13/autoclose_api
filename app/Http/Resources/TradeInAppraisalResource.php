<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TradeInAppraisalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'deal_id'          => $this->deal_id,
            'dealer_id'        => $this->dealer_id,
            'year'             => $this->year,
            'make'             => $this->make,
            'model'            => $this->model,
            'trim'             => $this->trim,
            'mileage'          => $this->mileage,
            'vin'              => $this->vin,
            'condition'        => $this->condition,
            'kbb_value'        => $this->kbb_value !== null ? round($this->kbb_value / 100, 2) : null,
            'black_book_value' => $this->black_book_value !== null ? round($this->black_book_value / 100, 2) : null,
            'dealer_offer'     => $this->dealer_offer !== null ? round($this->dealer_offer / 100, 2) : null,
            'accepted'         => $this->accepted,
            'responded_at'     => $this->responded_at?->toISOString(),
            'created_at'       => $this->created_at?->toISOString(),
            'updated_at'       => $this->updated_at?->toISOString(),
        ];
    }
}
