<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Dealer;
use App\Models\TradeInAppraisal;
use App\Models\User;

class TradeInService
{
    /**
     * Submit (create or replace) a trade-in appraisal for a deal (buyer action).
     * Also stores the trade-in vehicle summary on the deal.
     *
     * @param  array<string, mixed>  $data  Validated input
     */
    public function submit(Deal $deal, Dealer $dealer, array $data): TradeInAppraisal
    {
        $appraisal = TradeInAppraisal::updateOrCreate(
            ['deal_id' => $deal->id],
            array_merge($data, ['dealer_id' => $dealer->id])
        );

        $deal->update([
            'trade_in_vehicle' => array_intersect_key(
                $data,
                array_flip(['year', 'make', 'model', 'trim', 'mileage', 'vin', 'condition'])
            ),
        ]);

        return $appraisal;
    }

    /**
     * Get the trade-in appraisal for a deal.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getForDeal(Deal $deal): TradeInAppraisal
    {
        return TradeInAppraisal::where('deal_id', $deal->id)->firstOrFail();
    }

    /**
     * Record the dealer's offer on an appraisal.
     * Syncs `trade_in_value` on the deal when `dealer_offer` is provided.
     *
     * @param  array<string, mixed>  $data  Validated — may include `dealer_offer`
     */
    public function respond(TradeInAppraisal $appraisal, Deal $deal, array $data): TradeInAppraisal
    {
        $appraisal->update(array_merge($data, ['responded_at' => now()]));

        if (isset($data['dealer_offer'])) {
            $deal->update(['trade_in_value' => $data['dealer_offer']]);
        }

        return $appraisal->fresh();
    }
}
