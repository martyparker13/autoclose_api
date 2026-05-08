<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DealScenarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'deal_id'         => $this->deal_id,
            'label'           => $this->label,
            'term_months'     => $this->term_months,
            'down_payment'    => $this->down_payment,
            'sale_price'      => $this->sale_price,
            'fi_product_ids'  => $this->fi_product_ids ?? [],
            'apr'             => $this->apr,
            'monthly_payment' => $this->monthly_payment,
            'total_cost'      => $this->total_cost,
            'is_selected'     => (bool) $this->is_selected,
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
        ];
    }
}
