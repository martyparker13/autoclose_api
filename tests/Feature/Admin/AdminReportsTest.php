<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Deal;
use App\Models\Dealer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->superAdmin()->create();
    }

    public function test_super_admin_can_fetch_platform_summary(): void
    {
        $dealerA = Dealer::factory()->create(['is_active' => true]);
        $dealerB = Dealer::factory()->create(['is_active' => true]);

        $buyerA = User::factory()->create(['dealer_id' => $dealerA->id]);
        $buyerB = User::factory()->create(['dealer_id' => $dealerB->id]);

        $vehicleA = Vehicle::factory()->create(['dealer_id' => $dealerA->id, 'status' => 'available']);
        $vehicleB = Vehicle::factory()->create(['dealer_id' => $dealerB->id, 'status' => 'available']);

        Deal::factory()->create([
            'dealer_id'       => $dealerA->id,
            'vehicle_id'      => $vehicleA->id,
            'buyer_id'        => $buyerA->id,
            'status'          => 'delivered',
            'sale_price'      => 2500000,
            'total_fi_income' => 190000,
            'created_at'      => now()->subDays(2),
        ]);

        Deal::factory()->create([
            'dealer_id'       => $dealerB->id,
            'vehicle_id'      => $vehicleB->id,
            'buyer_id'        => $buyerB->id,
            'status'          => 'credit_submitted',
            'sale_price'      => 1800000,
            'total_fi_income' => 0,
            'created_at'      => now()->subDays(1),
        ]);

        ActivityLog::create([
            'dealer_id'  => $dealerA->id,
            'user_id'    => $this->superAdmin->id,
            'event'      => 'deal.status_changed',
            'model_type' => null,
            'model_id'   => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => now()->subHours(3),
        ]);

        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/reports/summary?period=30d')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'period',
                    'period_start',
                    'total_deals',
                    'closed_deals',
                    'revenue_cents',
                    'fi_income_cents',
                    'pending_credit',
                    'active_dealers',
                    'audit_events_24h',
                ],
            ])
            ->assertJsonPath('data.total_deals', 2)
            ->assertJsonPath('data.closed_deals', 1)
            ->assertJsonPath('data.pending_credit', 1)
            ->assertJsonPath('data.active_dealers', 2)
            ->assertJsonPath('data.audit_events_24h', 1);
    }

    public function test_super_admin_can_fetch_platform_trend_top_dealers_and_audit_activity(): void
    {
        $dealerA = Dealer::factory()->create(['name' => 'Alpha Auto']);
        $dealerB = Dealer::factory()->create(['name' => 'Bravo Motors']);

        $buyerA = User::factory()->create(['dealer_id' => $dealerA->id]);
        $buyerB = User::factory()->create(['dealer_id' => $dealerB->id]);

        $vehicleA = Vehicle::factory()->create(['dealer_id' => $dealerA->id, 'status' => 'available']);
        $vehicleB = Vehicle::factory()->create(['dealer_id' => $dealerB->id, 'status' => 'available']);

        Deal::factory()->create([
            'dealer_id'       => $dealerA->id,
            'vehicle_id'      => $vehicleA->id,
            'buyer_id'        => $buyerA->id,
            'status'          => 'delivered',
            'sale_price'      => 3100000,
            'total_fi_income' => 220000,
            'created_at'      => now()->subDays(5),
        ]);

        Deal::factory()->create([
            'dealer_id'       => $dealerB->id,
            'vehicle_id'      => $vehicleB->id,
            'buyer_id'        => $buyerB->id,
            'status'          => 'delivered',
            'sale_price'      => 1900000,
            'total_fi_income' => 90000,
            'created_at'      => now()->subDays(8),
        ]);

        ActivityLog::create([
            'dealer_id'  => $dealerA->id,
            'user_id'    => $this->superAdmin->id,
            'event'      => 'user.login',
            'model_type' => null,
            'model_id'   => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => now()->subDays(2),
        ]);

        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/reports/trend')
            ->assertOk()
            ->assertJsonCount(12, 'data');

        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/reports/top-dealers?limit=5')
            ->assertOk()
            ->assertJsonStructure(['data' => [['dealer_id', 'dealer_name', 'deals', 'revenue_cents', 'close_rate']]])
            ->assertJsonFragment(['dealer_name' => 'Alpha Auto'])
            ->assertJsonFragment(['dealer_name' => 'Bravo Motors']);

        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/reports/audit-activity?days=14')
            ->assertOk()
            ->assertJsonCount(14, 'data')
            ->assertJsonStructure(['data' => [['date', 'total', 'sensitive']]]);
    }

    public function test_non_super_admin_cannot_access_admin_reports(): void
    {
        $dealer = Dealer::factory()->create();
        $dealerAdmin = User::factory()->dealerAdmin()->create(['dealer_id' => $dealer->id]);

        $this->actingAs($dealerAdmin)
            ->getJson('/api/v1/admin/reports/summary')
            ->assertForbidden();
    }
}
