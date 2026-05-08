<?php

namespace App\Listeners;

use App\Events\DealStatusChanged;
use App\Services\Integrations\EContractService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class PushEContract implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly EContractService $eContracts,
    ) {}

    public function handle(DealStatusChanged $event): void
    {
        // Only push when transitioning into docs_pending
        if ($event->newStatus !== 'docs_pending') {
            return;
        }

        $deal   = $event->deal;
        $dealer = $deal->dealer;

        if (! $dealer) {
            Log::warning('PushEContract: deal has no dealer, skipping', ['deal_id' => $deal->id]);
            return;
        }

        try {
            $this->eContracts->push($dealer, $deal);
        } catch (\Throwable $e) {
            Log::error('PushEContract: unexpected exception', [
                'deal_id' => $deal->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
