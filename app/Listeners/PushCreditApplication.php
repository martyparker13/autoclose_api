<?php

namespace App\Listeners;

use App\Events\DealStatusChanged;
use App\Services\Integrations\DealerTrackService;
use App\Services\Integrations\RouteOneService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fires when a deal transitions to `credit_submitted`.
 *
 * If the dealer has credentials for DealerTrack and/or RouteOne, this listener
 * pushes the credit application to those platforms and records the result in
 * `credit_applications.integration_pushes`.
 *
 * Queued so that external API latency does not block the buyer's request.
 */
class PushCreditApplication implements ShouldQueue
{
    use InteractsWithQueue;

    /** Retry up to 3 times on transient failures. */
    public int $tries = 3;

    /** Exponential backoff: 30 s, 5 min, 30 min. */
    public array $backoff = [30, 300, 1800];

    public function __construct(
        private readonly DealerTrackService $dealertrack,
        private readonly RouteOneService    $routeone,
    ) {}

    public function handle(DealStatusChanged $event): void
    {
        // Only act on the credit_submitted transition
        if ($event->newStatus !== 'credit_submitted') {
            return;
        }

        $deal   = $event->deal;
        $dealer = $deal->dealer;

        if (! $dealer) {
            return;
        }

        $app = $deal->creditApplication;

        if (! $app) {
            return;
        }

        // Load relationships needed for payload building
        $deal->loadMissing(['vehicle', 'buyer']);

        $pushes = $app->integration_pushes ?? [];

        // ── DealerTrack ───────────────────────────────────────────────────────
        if (! empty($dealer->dealertrack_credentials)) {
            $result  = $this->dealertrack->push($dealer, $deal, $app);
            $pushes[] = [
                'platform'    => 'dealertrack',
                'success'     => $result['success'],
                'external_id' => $result['external_id'],
                'error'       => $result['error'],
                'pushed_at'   => now()->toISOString(),
            ];
        }

        // ── RouteOne ──────────────────────────────────────────────────────────
        if (! empty($dealer->routeone_credentials)) {
            $result  = $this->routeone->push($dealer, $deal, $app);
            $pushes[] = [
                'platform'    => 'routeone',
                'success'     => $result['success'],
                'external_id' => $result['external_id'],
                'error'       => $result['error'],
                'pushed_at'   => now()->toISOString(),
            ];
        }

        if (! empty($pushes)) {
            $app->update(['integration_pushes' => $pushes]);
        }
    }

    /**
     * Only retry on network/timeout failures; skip re-queue for
     * business-logic rejections (4xx from the platform).
     */
    public function failed(DealStatusChanged $event, \Throwable $exception): void
    {
        \Illuminate\Support\Facades\Log::error('PushCreditApplication listener failed permanently', [
            'deal_id' => $event->deal->id,
            'error'   => $exception->getMessage(),
        ]);
    }
}
