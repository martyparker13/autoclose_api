<?php

namespace Tests\Feature\Reporting;

use App\Models\ActivityLog;
use App\Models\Deal;
use App\Models\Dealer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealerWorkflowAutomationReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_dealer_admin_can_fetch_workflow_automation_report_for_own_dealer(): void
    {
        $dealer = Dealer::factory()->create();
        $otherDealer = Dealer::factory()->create();

        $admin = User::factory()->dealerAdmin()->create([
            'dealer_id' => $dealer->id,
        ]);

        ActivityLog::create([
            'dealer_id' => $dealer->id,
            'user_id' => $admin->id,
            'event' => 'workflow.reminder.resume_deal',
            'model_type' => Deal::class,
            'model_id' => 9001,
            'old_values' => null,
            'new_values' => ['audience' => 'buyer'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => now()->subDays(1),
        ]);

        ActivityLog::create([
            'dealer_id' => $dealer->id,
            'user_id' => $admin->id,
            'event' => 'workflow.escalation.docs_pending',
            'model_type' => Deal::class,
            'model_id' => 9002,
            'old_values' => null,
            'new_values' => ['audience' => 'dealer_team'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => now()->subHours(8),
        ]);

        ActivityLog::create([
            'dealer_id' => $otherDealer->id,
            'user_id' => null,
            'event' => 'workflow.next_step.schedule_delivery',
            'model_type' => Deal::class,
            'model_id' => 9999,
            'old_values' => null,
            'new_values' => ['audience' => 'dealer_team'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => now()->subHours(4),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/dealer/reports/workflow-automation?days=14')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'period_days',
                    'total_events',
                    'reminders',
                    'escalations',
                    'next_steps',
                    'unique_deals_touched',
                    'top_events' => [['event', 'total']],
                    'daily' => [['date', 'total']],
                ],
            ])
            ->assertJsonPath('data.total_events', 2)
            ->assertJsonPath('data.reminders', 1)
            ->assertJsonPath('data.escalations', 1)
            ->assertJsonPath('data.next_steps', 0)
            ->assertJsonPath('data.unique_deals_touched', 2);
    }

    public function test_non_dealer_staff_cannot_fetch_dealer_workflow_automation_report(): void
    {
        $dealer = Dealer::factory()->create();
        $buyer = User::factory()->create([
            'dealer_id' => $dealer->id,
            'role' => 'buyer',
        ]);

        $this->actingAs($buyer)
            ->getJson('/api/v1/dealer/reports/workflow-automation?days=14')
            ->assertForbidden();
    }

    public function test_dealer_admin_can_fetch_time_to_close_report(): void
    {
        $dealer = Dealer::factory()->create();
        $admin = User::factory()->dealerAdmin()->create(['dealer_id' => $dealer->id]);
        $buyer = User::factory()->create(['dealer_id' => $dealer->id, 'role' => 'buyer']);
        $vehicle = Vehicle::factory()->create(['dealer_id' => $dealer->id, 'status' => 'sold']);

        Deal::factory()->create([
            'dealer_id' => $dealer->id,
            'vehicle_id' => $vehicle->id,
            'buyer_id' => $buyer->id,
            'status' => 'delivered',
            'created_at' => now()->subDays(4),
            'updated_at' => now()->subDays(1),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/dealer/reports/time-to-close?period=30d')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'avg_days',
                    'sample_size',
                    'period',
                ],
            ])
            ->assertJsonPath('data.period', '30d')
            ->assertJsonPath('data.sample_size', 1);
    }
}
