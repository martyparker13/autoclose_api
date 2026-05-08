<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'dealer_id'        => $this->dealer_id,
            'status'           => $this->status,
            'source'           => $this->source,
            'sale_price'       => $this->sale_price !== null ? round($this->sale_price / 100, 2) : null,
            'down_payment'     => $this->down_payment !== null ? round($this->down_payment / 100, 2) : null,
            'trade_in_value'   => $this->trade_in_value !== null ? round($this->trade_in_value / 100, 2) : null,
            'trade_in_vehicle' => $this->trade_in_vehicle,
            'finance_amount'   => $this->finance_amount !== null ? round($this->finance_amount / 100, 2) : null,
            'apr'              => $this->apr,
            'term_months'      => $this->term_months,
            'monthly_payment'  => $this->monthly_payment !== null ? round($this->monthly_payment / 100, 2) : null,
            'total_fi_income'  => $this->total_fi_income !== null ? round($this->total_fi_income / 100, 2) : null,
            'lender'           => $this->lender,
            'notes'            => $this->notes,
            'econtract_pushes' => $this->econtract_pushes ?? [],
            'vehicle'          => new VehicleResource($this->whenLoaded('vehicle')),
            'buyer'            => new UserResource($this->whenLoaded('buyer')),
            'salesperson'      => new UserResource($this->whenLoaded('salesperson')),
            'fi_products'      => DealFiProductResource::collection($this->whenLoaded('dealFiProducts')),
            'credit_application'    => new CreditApplicationResource($this->whenLoaded('creditApplication')),
            'trade_in_appraisal'    => new TradeInAppraisalResource($this->whenLoaded('tradeInAppraisal')),
            'documents'             => DealDocumentResource::collection($this->whenLoaded('documents')),
            'delivery_appointment'  => new DeliveryAppointmentResource($this->whenLoaded('deliveryAppointment')),
            'created_at'       => $this->created_at?->toISOString(),
            'updated_at'       => $this->updated_at?->toISOString(),
        ];
    }
}
