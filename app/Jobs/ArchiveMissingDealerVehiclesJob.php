<?php

namespace App\Jobs;

use App\Models\Dealer;
use App\Models\DealerSyncRun;
use App\Services\InventoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ArchiveMissingDealerVehiclesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param list<string> $incomingVins
     * @param list<string> $incomingStocks
     */
    public function __construct(
        public readonly int $dealerId,
        public readonly int $syncRunId,
        public readonly array $incomingVins,
        public readonly array $incomingStocks,
    ) {
        $this->onQueue('sync');
    }

    public function handle(InventoryService $inventory): void
    {
        $dealer = Dealer::find($this->dealerId);
        if (! $dealer) {
            return;
        }

        $run = DealerSyncRun::where('id', $this->syncRunId)
            ->where('dealer_id', $this->dealerId)
            ->first();
        if (! $run) {
            return;
        }

        if ($run->status === 'queued') {
            $run->update([
                'status' => 'running',
                'started_at' => $run->started_at ?? now(),
            ]);
        }

        $archived = $inventory->archiveMissingFromSync($dealer, $this->incomingVins, $this->incomingStocks);

        $run->refresh();
        $run->update([
            'archived' => $run->archived + $archived,
            'processed_jobs' => $run->processed_jobs + 1,
        ]);

        if ($run->processed_jobs >= $run->total_jobs) {
            $run->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        }

    }

    public function failed(?Throwable $e): void
    {
        $run = DealerSyncRun::where('id', $this->syncRunId)
            ->where('dealer_id', $this->dealerId)
            ->first();

        if (! $run) {
            return;
        }

        $errors = is_array($run->errors) ? $run->errors : [];
        if ($e?->getMessage()) {
            $errors = array_slice(array_merge($errors, [$e->getMessage()]), 0, 100);
        }

        $run->update([
            'status' => 'failed',
            'completed_at' => now(),
            'errors' => $errors,
            'error_count' => $run->error_count + 1,
        ]);
    }
}
