<?php

namespace App\Services\Integrations;

use App\Events\DealStatusChanged;
use App\Models\ActivityLog;
use App\Models\CreditApplication;
use Illuminate\Support\Facades\Log;

/**
 * Processes an inbound credit decision from DealerTrack or RouteOne.
 *
 * Finds the credit application by the external_id stored in integration_pushes,
 * updates the decision fields, and transitions the deal status accordingly.
 * This is a system-initiated action — no dealer authorisation check is performed.
 */
class CreditDecisionService
{
    /**
     * Handle an inbound credit decision from an external platform.
     *
     * @param  string       $platform    'dealertrack' | 'routeone'
     * @param  string       $externalId  The application ID returned when we pushed
     * @param  string       $decision    'approved' | 'declined' | 'conditional'
     * @param  int|null     $approvedAmount  Cents
     * @param  float|null   $approvedApr
     * @param  int|null     $approvedTerm    Months
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \RuntimeException if the deal is not in a state that accepts a decision
     */
    public function handle(
        string $platform,
        string $externalId,
        string $decision,
        ?int   $approvedAmount = null,
        ?float $approvedApr    = null,
        ?int   $approvedTerm   = null,
    ): void {
        // ------------------------------------------------------------------
        // 1. Find the credit application by the external_id in integration_pushes
        // ------------------------------------------------------------------
        $app = CreditApplication::whereJsonContains(
            'integration_pushes',
            ['platform' => $platform, 'external_id' => $externalId],
        )->with('deal')->firstOrFail();

        $deal = $app->deal;

        if (! $deal) {
            Log::error('CreditDecisionService: credit application has no associated deal', [
                'credit_application_id' => $app->id,
                'platform'              => $platform,
                'external_id'           => $externalId,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 2. Guard — only process if the deal is still awaiting a decision
        // ------------------------------------------------------------------
        if ($deal->status !== 'credit_submitted') {
            Log::info('CreditDecisionService: deal is not in credit_submitted status, ignoring webhook', [
                'deal_id'    => $deal->id,
                'status'     => $deal->status,
                'platform'   => $platform,
                'external_id'=> $externalId,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 3. Normalise decision → deal status
        // ------------------------------------------------------------------
        $normalised = strtolower($decision);

        $dealStatus = match (true) {
            in_array($normalised, ['approved'], true)                           => 'credit_approved',
            in_array($normalised, ['declined', 'denied'], true)                  => 'credit_declined',
            in_array($normalised, ['conditional', 'counter_offer'], true)        => 'credit_approved', // treated as approved; dealer can refine
            default                                                               => null,
        };

        if ($dealStatus === null) {
            Log::warning('CreditDecisionService: unrecognised decision value, ignoring', [
                'decision'    => $decision,
                'platform'    => $platform,
                'external_id' => $externalId,
            ]);
            return;
        }

        // ------------------------------------------------------------------
        // 4. Update credit application
        // ------------------------------------------------------------------
        $updateFields = [
            'decision'   => $normalised === 'counter_offer' ? 'conditional' : $normalised,
            'decided_at' => now(),
        ];

        if ($approvedAmount !== null) {
            $updateFields['approved_amount'] = $approvedAmount;
        }
        if ($approvedApr !== null) {
            $updateFields['approved_apr'] = $approvedApr;
        }
        if ($approvedTerm !== null) {
            $updateFields['approved_term'] = $approvedTerm;
        }

        $app->update($updateFields);

        // ------------------------------------------------------------------
        // 5. Transition deal status
        // ------------------------------------------------------------------
        $oldStatus = $deal->status;
        $deal->update(['status' => $dealStatus]);

        ActivityLog::record(
            'deal.status_changed',
            $deal,
            ['status' => $oldStatus],
            ['status' => $dealStatus, 'source' => "webhook:{$platform}"],
        );

        DealStatusChanged::dispatch($deal->fresh(), $oldStatus, $dealStatus);

        Log::info('CreditDecisionService: deal transitioned via webhook', [
            'deal_id'     => $deal->id,
            'old_status'  => $oldStatus,
            'new_status'  => $dealStatus,
            'platform'    => $platform,
            'external_id' => $externalId,
        ]);
    }
}
