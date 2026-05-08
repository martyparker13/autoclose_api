<?php

namespace App\Services\Integrations;

use App\Events\DealStatusChanged;
use App\Models\Deal;
use App\Models\Dealer;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates electronic contract signing via DealerTrack or RouteOne.
 *
 * Platform selection (first match wins):
 *   1. DealerTrack credentials present → use DealerTrack
 *   2. RouteOne credentials present    → use RouteOne
 *   3. Neither                         → log warning and skip
 *
 * Each push is recorded in deals.econtract_pushes as:
 *   {platform, external_id, status, signing_url, pushed_at, signed_at, error}
 *
 * Status values: pending | signed | voided | error
 */
class EContractService
{
    public function __construct(
        private DealerTrackService $dealerTrack,
        private RouteOneService $routeOne,
    ) {}

    /**
     * Push the deal contract to the first available platform and record the result.
     */
    public function push(Dealer $dealer, Deal $deal): void
    {
        $platform = $this->selectPlatform($dealer);

        if ($platform === null) {
            Log::warning('EContractService: no integration credentials configured, skipping eContract push', [
                'dealer_id' => $dealer->id,
                'deal_id'   => $deal->id,
            ]);
            return;
        }

        $result = match ($platform) {
            'dealertrack' => $this->dealerTrack->pushContract($dealer, $deal),
            'routeone'    => $this->routeOne->pushContract($dealer, $deal),
        };

        $entry = [
            'platform'    => $platform,
            'external_id' => $result['external_id'],
            'status'      => $result['success'] ? 'pending' : 'error',
            'signing_url' => $result['signing_url'] ?? null,
            'pushed_at'   => now()->toIso8601String(),
            'signed_at'   => null,
            'error'       => $result['error'],
        ];

        $pushes   = $deal->econtract_pushes ?? [];
        $pushes[] = $entry;

        $deal->update(['econtract_pushes' => $pushes]);
    }

    /**
     * Handle a signed-contract webhook callback.
     *
     * Locates the deal by matching platform + external_id in econtract_pushes,
     * marks that push entry as signed, and auto-transitions the deal to docs_signed
     * if it is currently in docs_pending.
     */
    public function handleSigned(string $platform, string $externalId): void
    {
        /** @var Deal|null $deal */
        $deal = Deal::whereJsonContains('econtract_pushes', [
            'platform'    => $platform,
            'external_id' => $externalId,
        ])->first();

        if ($deal === null) {
            Log::warning('EContractService: signed callback received but no matching deal found', [
                'platform'    => $platform,
                'external_id' => $externalId,
            ]);
            return;
        }

        // Update the matching push entry
        $pushes = array_map(function (array $push) use ($platform, $externalId): array {
            if ($push['platform'] === $platform && $push['external_id'] === $externalId) {
                $push['status']    = 'signed';
                $push['signed_at'] = now()->toIso8601String();
                $push['error']     = null;
            }
            return $push;
        }, $deal->econtract_pushes ?? []);

        $deal->update(['econtract_pushes' => $pushes]);

        // Auto-transition to docs_signed only from docs_pending
        if ($deal->status === 'docs_pending') {
            $oldStatus = $deal->status;
            $deal->update(['status' => 'docs_signed']);
            DealStatusChanged::dispatch($deal, $oldStatus, 'docs_signed');

            Log::info('EContractService: deal transitioned to docs_signed', [
                'deal_id'     => $deal->id,
                'platform'    => $platform,
                'external_id' => $externalId,
            ]);
        }
    }

    /**
     * Select the first platform that has credentials configured.
     */
    private function selectPlatform(Dealer $dealer): ?string
    {
        if (!empty($dealer->dealertrack_credentials)) {
            return 'dealertrack';
        }

        if (!empty($dealer->routeone_credentials)) {
            return 'routeone';
        }

        return null;
    }
}
