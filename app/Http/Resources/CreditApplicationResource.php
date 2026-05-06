<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreditApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'deal_id'            => $this->deal_id,
            'buyer_id'           => $this->buyer_id,
            'dob'                => $this->dob,
            'annual_income'      => $this->annual_income !== null ? round($this->annual_income / 100, 2) : null,
            'employment_status'  => $this->employment_status,
            'employer_name'      => $this->employer_name,
            'employer_phone'     => $this->employer_phone,
            'monthly_housing'    => $this->monthly_housing !== null ? round($this->monthly_housing / 100, 2) : null,
            'housing_status'     => $this->housing_status,
            'years_at_employer'  => $this->years_at_employer,
            'credit_score_range' => $this->credit_score_range,
            'bureau_pull_type'   => $this->bureau_pull_type,
            'decision'           => $this->decision,
            'approved_amount'    => $this->approved_amount !== null ? round($this->approved_amount / 100, 2) : null,
            'approved_apr'       => $this->approved_apr,
            'approved_term'      => $this->approved_term,
            'created_at'         => $this->created_at?->toISOString(),
            'updated_at'         => $this->updated_at?->toISOString(),
        ];
    }
}
