<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ActivityLog;
use App\Services\DealWorkflowAutomationService;
use App\Services\ReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowAutomationController extends BaseController
{
    public function __construct(
        private readonly DealWorkflowAutomationService $workflow,
        private readonly ReportingService $reporting,
    ) {}

    /**
     * GET /dealer/settings/workflow-automation/overview?days=14
     */
    public function overview(Request $request): JsonResponse
    {
        $request->validate(['days' => ['nullable', 'integer', 'min:7', 'max:60']]);

        $dealer = app('current_dealer');
        $days = (int) $request->input('days', 14);

        $overview = $this->reporting->workflowAutomationOverviewForDealer($dealer, $days);

        $recentEvents = ActivityLog::query()
            ->where('dealer_id', $dealer->id)
            ->where('event', 'like', 'workflow.%')
            ->latest('created_at')
            ->limit(15)
            ->get(['id', 'event', 'model_id', 'new_values', 'created_at'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'event' => $row->event,
                'deal_id' => $row->model_id ? (int) $row->model_id : null,
                'audience' => is_array($row->new_values) ? ($row->new_values['audience'] ?? null) : null,
                'created_at' => $row->created_at?->toISOString(),
            ])
            ->all();

        return response()->json([
            'data' => array_merge($overview, [
                'recent_events' => $recentEvents,
            ]),
        ]);
    }

    /**
     * GET /dealer/settings/workflow-automation/runs?limit=10
     */
    public function runs(Request $request): JsonResponse
    {
        $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:25']]);

        $dealer = app('current_dealer');
        $limit = (int) $request->input('limit', 10);

        $runs = ActivityLog::query()
            ->where('dealer_id', $dealer->id)
            ->where('event', 'workflow.sweep.run')
            ->latest('created_at')
            ->limit($limit)
            ->get(['id', 'new_values', 'created_at'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'dry_run' => (bool) (($row->new_values['dry_run'] ?? true) === true),
                'stats' => is_array($row->new_values) ? ($row->new_values['stats'] ?? []) : [],
                'source' => is_array($row->new_values) ? ($row->new_values['source'] ?? null) : null,
                'executed_at' => $row->created_at?->toISOString(),
            ])
            ->all();

        return response()->json(['data' => $runs]);
    }

    /**
     * POST /dealer/settings/workflow-automation/run
     */
    public function run(Request $request): JsonResponse
    {
        $request->validate(['dry_run' => ['nullable', 'boolean']]);

        $dealer = app('current_dealer');
        $dryRun = (bool) $request->input('dry_run', true);

        $stats = $this->workflow->runSweep((int) $dealer->id, $dryRun);

        ActivityLog::record('workflow.sweep.run', null, [], [
            'dry_run' => $dryRun,
            'stats' => $stats,
            'source' => 'dealer-settings',
        ]);

        return response()->json([
            'data' => [
                'dealer_id' => (int) $dealer->id,
                'dry_run' => $dryRun,
                'stats' => $stats,
                'executed_at' => now()->toISOString(),
            ],
        ]);
    }
}
