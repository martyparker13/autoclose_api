<?php

namespace App\Jobs;

use App\Models\Dealer;
use App\Services\InventoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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

        $inventory->syncFromPayload($dealer, $this->chunk);
    }
}
