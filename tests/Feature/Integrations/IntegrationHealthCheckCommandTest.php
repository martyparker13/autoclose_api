<?php

namespace Tests\Feature\Integrations;

use App\Models\Dealer;
use App\Models\DealerSyncRun;
use App\Models\IntegrationWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class IntegrationHealthCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_check_logs_critical_when_failures_exist(): void
    {
        Log::spy();

        $dealer = Dealer::factory()->create();

        IntegrationWebhookEvent::create([
            'provider' => 'dealertrack',
            'event_key' => 'APPLICATION_DECISION_UPDATED:DT-1:approved',
            'payload_hash' => hash('sha256', '{"x":1}'),
            'status' => 'failed',
            'delivery_count' => 1,
            'payload' => ['x' => 1],
            'error' => 'test failure',
            'first_seen_at' => now()->subMinutes(5),
            'last_seen_at' => now()->subMinutes(2),
            'processed_at' => null,
        ]);

        DealerSyncRun::create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'dealer_id' => $dealer->id,
            'source' => 'dealertrack',
            'status' => 'failed',
            'archive_missing' => true,
            'total_records' => 10,
            'chunk_size' => 10,
            'total_jobs' => 2,
            'processed_jobs' => 2,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'archived' => 0,
            'error_count' => 1,
            'errors' => ['boom'],
        ]);

        $exit = Artisan::call('integrations:health-check', ['--minutes' => 30]);

        $this->assertSame(0, $exit);

        Log::shouldHaveReceived('critical')->once();
    }
}
