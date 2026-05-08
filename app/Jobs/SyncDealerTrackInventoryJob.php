<?php

namespace App\Jobs;

use App\Models\Dealer;
use App\Models\DealerSyncRun;
use App\Services\InventoryService;
use App\Services\Integrations\DealerTrackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SyncDealerTrackInventoryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    private const CHUNK_SIZE = 250;

    public function __construct(
        public readonly int $dealerId,
    ) {
        $this->onQueue('sync');
    }

    public function handle(DealerTrackService $dealertrack, InventoryService $inventory): void
    {
        $dealer = Dealer::find($this->dealerId);
        if (! $dealer) {
            Log::warning('SyncDealerTrackInventoryJob: dealer not found', ['dealer_id' => $this->dealerId]);
            return;
        }

        if (empty($dealer->dealertrack_credentials)) {
            Log::warning('SyncDealerTrackInventoryJob: no DealerTrack credentials, skipping', ['dealer_id' => $this->dealerId]);
            return;
        }

        try {
            $vehicles = $dealertrack->fetchInventory($dealer);
        } catch (Throwable $e) {
            Log::error('SyncDealerTrackInventoryJob: inventory fetch failed', [
                'dealer_id' => $this->dealerId,
                'error'     => $e->getMessage(),
            ]);
            $this->fail($e);
            return;
        }

        if (empty($vehicles)) {
            Log::info('SyncDealerTrackInventoryJob: DealerTrack returned 0 vehicles, nothing to sync', [
                'dealer_id' => $this->dealerId,
            ]);
            return;
        }

        $chunks    = array_chunk($vehicles, self::CHUNK_SIZE);
        $totalJobs = count($chunks) + 1; // +1 for archive job

        $syncRun = DealerSyncRun::create([
            'public_id'       => (string) Str::uuid(),
            'dealer_id'       => $dealer->id,
            'source'          => 'dealertrack',
            'status'          => 'queued',
            'archive_missing' => true,
            'total_records'   => count($vehicles),
            'chunk_size'      => self::CHUNK_SIZE,
            'total_jobs'      => $totalJobs,
            'processed_jobs'  => 0,
            'created'         => 0,
            'updated'         => 0,
            'skipped'         => 0,
            'archived'        => 0,
            'error_count'     => 0,
            'errors'          => [],
        ]);

        $jobs = [];
        foreach ($chunks as $chunk) {
            $jobs[] = new SyncDealerVehiclesChunkJob($dealer->id, $syncRun->id, $chunk);
        }

        $incomingVins = array_values(array_unique(array_filter(array_map(
            fn ($row) => isset($row['vin']) ? strtoupper(trim((string) $row['vin'])) : null,
            $vehicles,
        ))));

        $incomingStocks = array_values(array_unique(array_filter(array_map(
            fn ($row) => isset($row['stock_number']) ? trim((string) $row['stock_number']) : null,
            $vehicles,
        ))));

        $jobs[] = new ArchiveMissingDealerVehiclesJob($dealer->id, $syncRun->id, $incomingVins, $incomingStocks);

        Bus::chain($jobs)->dispatch();

        Log::info('SyncDealerTrackInventoryJob: sync chain dispatched', [
            'dealer_id'    => $dealer->id,
            'sync_run_id'  => $syncRun->public_id,
            'total_jobs'   => $totalJobs,
            'total_records'=> count($vehicles),
        ]);
    }
}
