<?php

namespace App\Jobs;

use App\Models\Dealer;
use App\Models\DealerSyncRun;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncDealerVehiclesChunkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param list<array<string,mixed>> $chunk
     */
    public function __construct(
        public readonly int $dealerId,
        public readonly int $syncRunId,
        public readonly array $chunk,
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

        $result = $inventory->syncFromPayload($dealer, $this->chunk);

        DB::transaction(function () use ($run, $result): void {
            $run->refresh();

            $errors = is_array($run->errors) ? $run->errors : [];
            if (! empty($result['errors'])) {
                $errors = array_slice(array_merge($errors, $result['errors']), 0, 100);
            }

            $run->update([
                'created' => $run->created + $result['created'],
                'updated' => $run->updated + $result['updated'],
                'skipped' => $run->skipped + $result['skipped'],
                'error_count' => $run->error_count + count($result['errors']),
                'processed_jobs' => $run->processed_jobs + 1,
                'errors' => $errors,
            ]);

            if ($run->processed_jobs >= $run->total_jobs) {
                $run->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
            }
        });

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
