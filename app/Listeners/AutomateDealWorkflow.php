<?php

namespace App\Listeners;

use App\Events\DealStatusChanged;
use App\Services\DealWorkflowAutomationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class AutomateDealWorkflow implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly DealWorkflowAutomationService $workflow,
    ) {}

    public function handle(DealStatusChanged $event): void
    {
        $this->workflow->handleStatusChanged($event->deal, $event->oldStatus, $event->newStatus);
    }
}
