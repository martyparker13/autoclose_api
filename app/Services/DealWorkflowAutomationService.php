<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Deal;
use App\Models\User;
use App\Notifications\DealWorkflowActionNotification;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class DealWorkflowAutomationService
{
    /**
     * Handle immediate workflow prompts when a deal status changes.
     *
     * @return array<string, int>
     */
    public function handleStatusChanged(Deal $deal, string $oldStatus, string $newStatus): array
    {
        $deal->loadMissing(['dealer', 'buyer', 'deliveryAppointment']);

        $sent = 0;

        if ($newStatus === 'credit_approved') {
            $sent += $this->notifyDealerTeam(
                $deal,
                'workflow.next_step.docs_prepare',
                'Credit Approved - Prepare Docs',
                "Deal #{$deal->id} is credit-approved. Prepare and send required documents to keep momentum.",
                "/admin/deals/{$deal->id}",
                ['old_status' => $oldStatus, 'new_status' => $newStatus],
                2,
            ) ? 1 : 0;
        }

        if ($newStatus === 'docs_signed' && ! $deal->deliveryAppointment) {
            $sent += $this->notifyDealerTeam(
                $deal,
                'workflow.next_step.schedule_delivery',
                'Docs Signed - Schedule Delivery',
                "Deal #{$deal->id} has signed docs but no delivery appointment. Schedule delivery now.",
                "/admin/deals/{$deal->id}",
                ['old_status' => $oldStatus, 'new_status' => $newStatus],
                2,
            ) ? 1 : 0;
        }

        return ['status_change_prompts' => $sent];
    }

    /**
     * Sweep stale deals and send reminders/escalations.
     *
     * @return array<string, int>
     */
    public function runSweep(?int $dealerId = null, bool $dryRun = false): array
    {
        $stats = [
            'stale_draft_reminders' => 0,
            'credit_decision_escalations' => 0,
            'docs_pending_escalations' => 0,
            'delivery_schedule_escalations' => 0,
            'status_change_prompts' => 0,
        ];

        $stats['stale_draft_reminders'] = $this->processStaleDrafts($dealerId, $dryRun);
        $stats['credit_decision_escalations'] = $this->processStaleCreditSubmitted($dealerId, $dryRun);
        $stats['docs_pending_escalations'] = $this->processStaleCreditApproved($dealerId, $dryRun);
        $stats['delivery_schedule_escalations'] = $this->processSignedWithoutDelivery($dealerId, $dryRun);

        return $stats;
    }

    private function processStaleDrafts(?int $dealerId, bool $dryRun): int
    {
        $count = 0;

        $this->baseQueryForStatus('draft', $dealerId)
            ->where('updated_at', '<=', now()->subHours(24))
            ->chunkById(100, function ($deals) use ($dryRun, &$count): void {
                /** @var \Illuminate\Database\Eloquent\Collection<int, Deal> $deals */
                foreach ($deals as $deal) {
                    /** @var Deal $deal */
                    $deal->loadMissing('buyer');
                    if (! $deal->buyer || ! $this->canRunEvent('workflow.reminder.resume_deal', $deal, 24)) {
                        continue;
                    }

                    $count++;
                    if ($dryRun) {
                        continue;
                    }

                    $deal->buyer->notify(new DealWorkflowActionNotification(
                        $deal,
                        'Complete Your Deal',
                        "Your deal #{$deal->id} is still in draft. Continue where you left off to move it forward.",
                        "/deals/{$deal->id}",
                        'workflow.reminder.resume_deal',
                    ));

                    ActivityLog::record('workflow.reminder.resume_deal', $deal, [], [
                        'audience' => 'buyer',
                        'deal_status' => $deal->status,
                    ]);
                }
            });

        return $count;
    }

    private function processStaleCreditSubmitted(?int $dealerId, bool $dryRun): int
    {
        $count = 0;

        $this->baseQueryForStatus('credit_submitted', $dealerId)
            ->where('updated_at', '<=', now()->subHours(24))
            ->chunkById(100, function ($deals) use ($dryRun, &$count): void {
                /** @var \Illuminate\Database\Eloquent\Collection<int, Deal> $deals */
                foreach ($deals as $deal) {
                    /** @var Deal $deal */
                    if (! $this->canRunEvent('workflow.escalation.credit_decision_overdue', $deal, 24)) {
                        continue;
                    }

                    $count++;
                    if ($dryRun) {
                        continue;
                    }

                    $this->notifyDealerTeam(
                        $deal,
                        'workflow.escalation.credit_decision_overdue',
                        'Credit Decision Overdue',
                        "Deal #{$deal->id} has been awaiting credit decision for over 24 hours.",
                        "/admin/deals/{$deal->id}",
                        ['deal_status' => $deal->status],
                        24,
                        false,
                    );
                }
            });

        return $count;
    }

    private function processStaleCreditApproved(?int $dealerId, bool $dryRun): int
    {
        $count = 0;

        $this->baseQueryForStatus('credit_approved', $dealerId)
            ->where('updated_at', '<=', now()->subHours(24))
            ->chunkById(100, function ($deals) use ($dryRun, &$count): void {
                /** @var \Illuminate\Database\Eloquent\Collection<int, Deal> $deals */
                foreach ($deals as $deal) {
                    /** @var Deal $deal */
                    $deal->loadMissing('documents');

                    if ($deal->documents->isNotEmpty() || ! $this->canRunEvent('workflow.escalation.docs_pending', $deal, 24)) {
                        continue;
                    }

                    $count++;
                    if ($dryRun) {
                        continue;
                    }

                    $this->notifyDealerTeam(
                        $deal,
                        'workflow.escalation.docs_pending',
                        'Docs Still Pending',
                        "Deal #{$deal->id} was approved but still has no uploaded documents after 24 hours.",
                        "/admin/deals/{$deal->id}",
                        ['deal_status' => $deal->status],
                        24,
                        false,
                    );
                }
            });

        return $count;
    }

    private function processSignedWithoutDelivery(?int $dealerId, bool $dryRun): int
    {
        $count = 0;

        $this->baseQueryForStatus('docs_signed', $dealerId)
            ->where('updated_at', '<=', now()->subHours(24))
            ->chunkById(100, function ($deals) use ($dryRun, &$count): void {
                /** @var \Illuminate\Database\Eloquent\Collection<int, Deal> $deals */
                foreach ($deals as $deal) {
                    /** @var Deal $deal */
                    $deal->loadMissing('deliveryAppointment');

                    if ($deal->deliveryAppointment || ! $this->canRunEvent('workflow.escalation.delivery_schedule_missing', $deal, 24)) {
                        continue;
                    }

                    $count++;
                    if ($dryRun) {
                        continue;
                    }

                    $this->notifyDealerTeam(
                        $deal,
                        'workflow.escalation.delivery_schedule_missing',
                        'Delivery Scheduling Needed',
                        "Deal #{$deal->id} has signed docs but no delivery appointment has been scheduled.",
                        "/admin/deals/{$deal->id}",
                        ['deal_status' => $deal->status],
                        24,
                        false,
                    );
                }
            });

        return $count;
    }

    /** @return Builder<Deal> */
    private function baseQueryForStatus(string $status, ?int $dealerId): Builder
    {
        return Deal::query()
            ->where('status', $status)
            ->when($dealerId, fn (Builder $q) => $q->where('dealer_id', $dealerId))
            ->orderBy('id');
    }

    private function notifyDealerTeam(
        Deal $deal,
        string $workflowEvent,
        string $title,
        string $message,
        string $actionPath,
        array $metadata,
        int $cooldownHours,
        bool $guardCooldown = true,
    ): bool {
        if ($guardCooldown && ! $this->canRunEvent($workflowEvent, $deal, $cooldownHours)) {
            return false;
        }

        $recipients = User::query()
            ->where('dealer_id', $deal->dealer_id)
            ->whereIn('role', ['dealer_admin', 'dealer_staff'])
            ->get();
        /** @var \Illuminate\Database\Eloquent\Collection<int, User> $recipients */

        if ($recipients->isEmpty()) {
            return false;
        }

        foreach ($recipients as $recipient) {
            $recipient->notify(new DealWorkflowActionNotification(
                $deal,
                $title,
                $message,
                $actionPath,
                $workflowEvent,
            ));
        }

        ActivityLog::record($workflowEvent, $deal, [], array_merge([
            'audience' => 'dealer_team',
            'recipients' => $recipients->count(),
        ], $metadata));

        return true;
    }

    private function canRunEvent(string $workflowEvent, Deal $deal, int $cooldownHours): bool
    {
        $since = Carbon::now()->subHours($cooldownHours);

        return ! ActivityLog::query()
            ->where('event', $workflowEvent)
            ->where('model_type', Deal::class)
            ->where('model_id', $deal->id)
            ->where('created_at', '>=', $since)
            ->exists();
    }
}
