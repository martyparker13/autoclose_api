<?php

namespace App\Console\Commands;

use App\Services\DealWorkflowAutomationService;
use Illuminate\Console\Command;

class RunDealWorkflowAutomationCommand extends Command
{
    protected $signature = 'deals:run-workflow-automation
                            {--dealer-id= : Limit automation sweep to one dealer id}
                            {--dry-run : Report candidates without sending notifications}';

    protected $description = 'Run deal workflow reminders/escalations for stale deals';

    public function __construct(
        private readonly DealWorkflowAutomationService $workflow,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dealerId = $this->option('dealer-id');
        $dryRun = (bool) $this->option('dry-run');

        $stats = $this->workflow->runSweep(
            dealerId: $dealerId ? (int) $dealerId : null,
            dryRun: $dryRun,
        );

        $this->info('Deal workflow automation completed.');
        $this->line('Dealer filter: ' . ($dealerId ?: 'all'));
        $this->line('Dry run: ' . ($dryRun ? 'yes' : 'no'));
        $this->table(
            ['Metric', 'Count'],
            [
                ['stale_draft_reminders', (string) $stats['stale_draft_reminders']],
                ['credit_decision_escalations', (string) $stats['credit_decision_escalations']],
                ['docs_pending_escalations', (string) $stats['docs_pending_escalations']],
                ['delivery_schedule_escalations', (string) $stats['delivery_schedule_escalations']],
                ['status_change_prompts', (string) $stats['status_change_prompts']],
            ]
        );

        return self::SUCCESS;
    }
}
