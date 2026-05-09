<?php

namespace Tests\Feature\Webhooks;

use App\Models\CreditApplication;
use App\Models\Deal;
use App\Models\Dealer;
use App\Models\IntegrationWebhookEvent;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationWebhookDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dealertrack_decision_webhook_is_processed_once_for_duplicate_payloads(): void
    {
        $dealer = Dealer::factory()->create();
        $buyer = User::factory()->create(['dealer_id' => $dealer->id, 'role' => 'buyer']);
        $vehicle = Vehicle::factory()->create(['dealer_id' => $dealer->id]);

        $deal = Deal::factory()->create([
            'dealer_id' => $dealer->id,
            'vehicle_id' => $vehicle->id,
            'buyer_id' => $buyer->id,
            'status' => 'credit_submitted',
        ]);

        CreditApplication::factory()->create([
            'deal_id' => $deal->id,
            'buyer_id' => $buyer->id,
            'integration_pushes' => [[
                'platform' => 'dealertrack',
                'external_id' => 'DT-ABC-1',
            ]],
        ]);

        $payload = [
            'eventType' => 'APPLICATION_DECISION_UPDATED',
            'applicationId' => 'DT-ABC-1',
            'decision' => [
                'status' => 'APPROVED',
                'amount' => 2550000,
                'apr' => 5.49,
                'termMonths' => 60,
            ],
        ];

        $this->postJson('/api/v1/webhooks/dealertrack', $payload)
            ->assertOk()
            ->assertJsonPath('received', true);

        $this->postJson('/api/v1/webhooks/dealertrack', $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $event = IntegrationWebhookEvent::query()
            ->where('provider', 'dealertrack')
            ->where('event_key', 'APPLICATION_DECISION_UPDATED:DT-ABC-1:approved')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(2, $event->delivery_count);
        $this->assertContains($event->status, ['processed', 'failed']);
    }

    public function test_routeone_contract_signed_webhook_is_processed_once_for_duplicate_payloads(): void
    {
        $dealer = Dealer::factory()->create();
        $buyer = User::factory()->create(['dealer_id' => $dealer->id, 'role' => 'buyer']);
        $vehicle = Vehicle::factory()->create(['dealer_id' => $dealer->id]);

        $deal = Deal::factory()->create([
            'dealer_id' => $dealer->id,
            'vehicle_id' => $vehicle->id,
            'buyer_id' => $buyer->id,
            'status' => 'docs_pending',
            'econtract_pushes' => [[
                'platform' => 'routeone',
                'external_id' => 'RO-C-1',
            ]],
        ]);

        $payload = [
            'event' => 'contract_signed',
            'contract_id' => 'RO-C-1',
        ];

        $this->postJson('/api/v1/webhooks/routeone', $payload)
            ->assertOk()
            ->assertJsonPath('received', true);

        $this->postJson('/api/v1/webhooks/routeone', $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $event = IntegrationWebhookEvent::query()
            ->where('provider', 'routeone')
            ->where('event_key', 'contract_signed:RO-C-1')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(2, $event->delivery_count);
        $this->assertContains($event->status, ['processed', 'failed']);
    }
}
