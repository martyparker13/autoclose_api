<?php

namespace Tests\Feature\Workflow;

use App\Models\ActivityLog;
use App\Models\Deal;
use App\Models\Dealer;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\DealWorkflowActionNotification;
use App\Services\DealWorkflowAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DealWorkflowAutomationTest extends TestCase
{
    use RefreshDatabase;

    private DealWorkflowAutomationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DealWorkflowAutomationService::class);
    }

    public function test_status_change_to_credit_approved_notifies_dealer_team_once_with_cooldown(): void
    {
        Notification::fake();

        $dealer = Dealer::factory()->create();
        $admin  = User::factory()->dealerAdmin()->create(['dealer_id' => $dealer->id]);
        $staff  = User::factory()->dealerStaff()->create(['dealer_id' => $dealer->id]);
        $buyer  = User::factory()->create(['dealer_id' => $dealer->id]);
        $vehicle = Vehicle::factory()->create(['dealer_id' => $dealer->id, 'status' => 'available']);

        $deal = Deal::factory()->create([
            'dealer_id' => $dealer->id,
            'vehicle_id' => $vehicle->id,
            'buyer_id' => $buyer->id,
            'status' => 'credit_approved',
        ]);

        $this->service->handleStatusChanged($deal, 'credit_submitted', 'credit_approved');
        $this->service->handleStatusChanged($deal, 'credit_submitted', 'credit_approved');

        Notification::assertSentTo([$admin, $staff], DealWorkflowActionNotification::class);

        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'workflow.next_step.docs_prepare',
            'model_type' => Deal::class,
            'model_id' => $deal->id,
        ]);
    }

    public function test_sweep_sends_buyer_reminder_for_stale_draft_and_logs_event(): void
    {
        Notification::fake();

        $dealer = Dealer::factory()->create();
        $buyer  = User::factory()->create(['dealer_id' => $dealer->id]);
        $vehicle = Vehicle::factory()->create(['dealer_id' => $dealer->id, 'status' => 'available']);

        $deal = Deal::factory()->create([
            'dealer_id' => $dealer->id,
            'vehicle_id' => $vehicle->id,
            'buyer_id' => $buyer->id,
            'status' => 'draft',
            'updated_at' => now()->subHours(30),
        ]);

        $stats = $this->service->runSweep();

        $this->assertSame(1, $stats['stale_draft_reminders']);

        Notification::assertSentTo($buyer, DealWorkflowActionNotification::class, function (DealWorkflowActionNotification $notification) use ($deal): bool {
            return $notification->deal->id === $deal->id
                && $notification->workflowEvent === 'workflow.reminder.resume_deal';
        });

        $this->assertDatabaseHas('activity_logs', [
            'event' => 'workflow.reminder.resume_deal',
            'model_type' => Deal::class,
            'model_id' => $deal->id,
        ]);
    }

    public function test_sweep_dry_run_reports_counts_without_sending_notifications_or_logs(): void
    {
        Notification::fake();

        $dealer = Dealer::factory()->create();
        $buyer  = User::factory()->create(['dealer_id' => $dealer->id]);
        $vehicle = Vehicle::factory()->create(['dealer_id' => $dealer->id, 'status' => 'available']);

        Deal::factory()->create([
            'dealer_id' => $dealer->id,
            'vehicle_id' => $vehicle->id,
            'buyer_id' => $buyer->id,
            'status' => 'draft',
            'updated_at' => now()->subHours(30),
        ]);

        $stats = $this->service->runSweep(dryRun: true);

        $this->assertSame(1, $stats['stale_draft_reminders']);
        Notification::assertNothingSent();
        $this->assertSame(0, ActivityLog::query()->count());
    }
}
