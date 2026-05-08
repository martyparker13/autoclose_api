<?php

namespace App\Console\Commands;

use App\Jobs\SyncDealerTrackInventoryJob;
use App\Models\Dealer;
use Illuminate\Console\Command;

class SyncDealerTrackInventoryCommand extends Command
{
    protected $signature = 'inventory:sync-dealertrack
                            {--dealer-id= : Only sync a specific dealer (by DB id)}';

    protected $description = 'Pull inventory from DealerTrack for all connected dealers and upsert into AutoClose';

    public function handle(): int
    {
        $dealerId = $this->option('dealer-id');

        $query = Dealer::query()
            ->whereNotNull('dealertrack_credentials')
            ->where('is_active', true);

        if ($dealerId) {
            $query->where('id', (int) $dealerId);
        }

        $dealers = $query->get();

        if ($dealers->isEmpty()) {
            $this->info('No dealers with DealerTrack credentials found.');
            return self::SUCCESS;
        }

        foreach ($dealers as $dealer) {
            SyncDealerTrackInventoryJob::dispatch($dealer->id);
            $this->info("Dispatched sync for dealer: {$dealer->name} (id={$dealer->id})");
        }

        $this->info("Dispatched {$dealers->count()} DealerTrack inventory sync job(s).");

        return self::SUCCESS;
    }
}
