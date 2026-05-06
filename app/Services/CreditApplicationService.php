<?php

namespace App\Services;

use App\Models\CreditApplication;
use App\Models\Deal;
use App\Models\User;

class CreditApplicationService
{
    /**
     * Get the credit application for a deal.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getForDeal(Deal $deal): CreditApplication
    {
        return CreditApplication::where('deal_id', $deal->id)->firstOrFail();
    }

    /**
     * Create or replace the credit application for a deal (buyer action).
     * Transitions the deal status to `credit_submitted` if currently `draft`.
     *
     * @param  array<string, mixed>  $data  Validated — must include `ssn`
     */
    public function submit(Deal $deal, User $buyer, array $data): CreditApplication
    {
        $ssn = $data['ssn'];
        unset($data['ssn']);

        $application = CreditApplication::updateOrCreate(
            ['deal_id' => $deal->id],
            array_merge($data, [
                'buyer_id'      => $buyer->id,
                'ssn_encrypted' => $ssn,
                'decision'      => 'pending',
                'submitted_at'  => now(),
            ])
        );

        if ($deal->status === 'draft') {
            $deal->update(['status' => 'credit_submitted']);
        }

        return $application;
    }

    /**
     * Update the credit decision (dealer staff / admin action).
     * Syncs the deal status based on the new decision.
     *
     * @param  array<string, mixed>  $data  Validated — may include `decision`
     */
    public function updateDecision(CreditApplication $application, Deal $deal, array $data): CreditApplication
    {
        if (! empty($data['decision']) && $data['decision'] !== 'pending') {
            $data['decided_at'] = now();
        }

        $application->update($data);

        if (isset($data['decision'])) {
            $newDealStatus = match ($data['decision']) {
                'approved', 'conditional' => 'credit_approved',
                'declined'                => 'credit_declined',
                default                   => $deal->status,
            };
            $deal->update(['status' => $newDealStatus]);
        }

        return $application->fresh();
    }
}
