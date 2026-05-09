<?php

namespace Tests\Feature\Workflow;

use App\Models\ActivityLog;
use App\Models\Deal;
use App\Models\Dealer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowAutomationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dealer_admin_can_fetch_workflow_settings_overview(): void
    {
        $dealer = Dealer::factory()->create();
        $otherDealer = Dealer::factory()->create();

        $admin = User::factory()->dealerAdmin()->create(['dealer_id' => $dealer->id]);

        ActivityLog::create([
            'dealer_id' => $dealer->id,
            'user_id' => $admin->id,
            'event' => 'workflow.reminder.resume_deal',
            'model_type' => Deal::class,
            'model_id' => 2001,
            'old_values' => null,
            'new_values' => ['audience' => 'buyer'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => now()->subHours(8),
        ]);

        ActivityLog::create([
            'dealer_id' => $dealer->id,
            'user_id' => $admin->id,
            'event' => 'workflow.escalation.docs_pending',
            'model_type' => Deal::class,
            'model_id' => 2002,
            'old_values' => null,
            'new_values' => ['audience' => 'dealer_team'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => now()->subHours(4),
        ]);

        ActivityLog::create([
            'dealer_id' => $otherDealer->id,
            'user_id' => null,
            'event' => 'workflow.next_step.docs_prepare',
            'model_type' => Deal::class,
            'model_id' => 9999,
            'old_values' => null,
            'new_values' => ['audience' => 'dealer_team'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => now()->subHours(2),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/dealer/settings/workflow-automation/overview?days=14')
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
                    'recent_events' => [['id', 'event', 'deal_id', 'audience', 'created_at']],
                ],
            ])
            ->assertJsonPath('data.total_events', 2)
            ->assertJsonPath('data.reminders', 1)
            ->assertJsonPath('data.escalations', 1)
            ->assertJsonPath('data.next_steps', 0)
            ->assertJsonCount(2, 'data.recent_events');
    }

    public function test_dealer_admin_can_run_dry_workflow_sweep(): void
    {
        $dealer = Dealer::factory()->create();
        $admin = User::factory()->dealerAdmin()->create(['dealer_id' => $dealer->id]);
        $buyer = User::factory()->create(['dealer_id' => $dealer->id, 'role' => 'buyer']);
        $vehicle = Vehicle::factory()->create(['dealer_id' => $dealer->id, 'status' => 'available']);

        Deal::factory()->create([
            'dealer_id' => $dealer->id,
            'vehicle_id' => $vehicle->id,
            'buyer_id' => $buyer->id,
            'status' => 'draft',
            'updated_at' => now()->subHours(30),
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/dealer/settings/workflow-automation/run', ['dry_run' => true])
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'dealer_id',
                    'dry_run',
                    'stats' => [
                        'stale_draft_reminders',
                        'credit_decision_escalations',
                        'docs_pending_escalations',
                        'delivery_schedule_escalations',
                        'status_change_prompts',
                    ],
                    'executed_at',
                ],
            ])
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.stats.stale_draft_reminders', 1);
    }

    public function test_dealer_staff_cannot_run_workflow_sweep_settings_action(): void
    {
        $dealer = Dealer::factory()->create();
        $staff = User::factory()->dealerStaff()->create(['dealer_id' => $dealer->id]);

        $this->actingAs($staff)
            ->postJson('/api/v1/dealer/settings/workflow-automation/run', ['dry_run' => true])
            ->assertForbidden();
    }
}
