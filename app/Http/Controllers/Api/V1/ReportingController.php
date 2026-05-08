<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\ReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportingController extends BaseController
{
    public function __construct(
        private readonly ReportingService $reporting,
    ) {}

    /**
     * GET /dealer/reports/summary?period=30d
     *
     * KPI summary: total deals, closed deals, revenue, F&I income, avg sale price.
     * Includes period-over-period deltas.
     */
    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'period' => ['nullable', 'in:7d,30d,90d,1y'],
        ]);

        $dealer = app('current_dealer');
        $data   = $this->reporting->summary($dealer, $request->input('period', '30d'));

        return response()->json(['data' => $data]);
    }

    /**
     * GET /dealer/reports/funnel
     *
     * Deal counts per status — the pipeline funnel.
     */
    public function funnel(Request $request): JsonResponse
    {
        $dealer = app('current_dealer');
        $data   = $this->reporting->dealFunnel($dealer);

        return response()->json(['data' => $data]);
    }

    /**
     * GET /dealer/reports/trend
     *
     * Monthly deal volume and revenue for the trailing 12 months.
     */
    public function trend(Request $request): JsonResponse
    {
        $dealer = app('current_dealer');
        $data   = $this->reporting->monthlyTrend($dealer);

        return response()->json(['data' => $data]);
    }

    /**
     * GET /dealer/reports/top-vehicles?limit=10
     *
     * Top vehicles by closed deals count.
     */
    public function topVehicles(Request $request): JsonResponse
    {
        $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:50']]);

        $dealer = app('current_dealer');
        $data   = $this->reporting->topVehicles($dealer, (int) $request->input('limit', 10));

        return response()->json(['data' => $data]);
    }

    /**
     * GET /dealer/reports/top-staff?limit=5
     *
     * Top staff by closed deals.
     */
    public function topStaff(Request $request): JsonResponse
    {
        $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:20']]);

        $dealer = app('current_dealer');
        $data   = $this->reporting->topStaff($dealer, (int) $request->input('limit', 5));

        return response()->json(['data' => $data]);
    }

    /**
     * GET /dealer/reports/inventory
     *
     * Inventory snapshot: available / pending / sold counts.
     */
    public function inventory(Request $request): JsonResponse
    {
        $dealer = app('current_dealer');
        $data   = $this->reporting->inventorySnapshot($dealer);

        return response()->json(['data' => $data]);
    }

    /**
     * GET /dealer/reports/time-to-close?period=30d
     *
     * Average days from deal open to delivered.
     */
    public function timeToClose(Request $request): JsonResponse
    {
        $request->validate(['period' => ['nullable', 'in:7d,30d,90d,1y']]);

        $dealer = app('current_dealer');
        $data   = $this->reporting->timeToClose($dealer, $request->input('period', '30d'));

        return response()->json(['data' => $data]);
    }

    /**
     * GET /dealer/reports/fi-attach-rate?period=30d
     *
     * F&I product attach rate per product.
     */
    public function fiAttachRate(Request $request): JsonResponse
    {
        $request->validate(['period' => ['nullable', 'in:7d,30d,90d,1y']]);

        $dealer = app('current_dealer');
        $data   = $this->reporting->fiAttachRate($dealer, $request->input('period', '30d'));

        return response()->json(['data' => $data]);
    }

    /**
     * GET /dealer/reports/credit-approval?period=30d
     *
     * Credit approval rate overall and by lender.
     */
    public function creditApproval(Request $request): JsonResponse
    {
        $request->validate(['period' => ['nullable', 'in:7d,30d,90d,1y']]);

        $dealer = app('current_dealer');
        $data   = $this->reporting->creditApprovalRate($dealer, $request->input('period', '30d'));

        return response()->json(['data' => $data]);
    }

    /**
     * GET /admin/reports/summary?period=30d
     *
     * Platform-wide KPI summary for super admins.
     */
    public function adminSummary(Request $request): JsonResponse
    {
        $request->validate(['period' => ['nullable', 'in:7d,30d,90d,1y']]);

        $data = $this->reporting->summaryGlobal($request->input('period', '30d'));

        return response()->json(['data' => $data]);
    }

    /**
     * GET /admin/reports/trend
     *
     * Platform-wide monthly trend for trailing 12 months.
     */
    public function adminTrend(Request $request): JsonResponse
    {
        $data = $this->reporting->monthlyTrendGlobal();

        return response()->json(['data' => $data]);
    }

    /**
     * GET /admin/reports/top-dealers?limit=10
     *
     * Top dealers by closed deals and revenue.
     */
    public function adminTopDealers(Request $request): JsonResponse
    {
        $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:50']]);

        $data = $this->reporting->topDealers((int) $request->input('limit', 10));

        return response()->json(['data' => $data]);
    }

    /**
     * GET /admin/reports/audit-activity?days=14
     *
     * Daily audit activity counts for super admins.
     */
    public function adminAuditActivity(Request $request): JsonResponse
    {
        $request->validate(['days' => ['nullable', 'integer', 'min:7', 'max:60']]);

        $data = $this->reporting->auditActivity((int) $request->input('days', 14));

        return response()->json(['data' => $data]);
    }

    /**
     * GET /admin/reports/workflow-automation?days=14
     *
     * Workflow automation health and volume for super admins.
     */
    public function adminWorkflowAutomation(Request $request): JsonResponse
    {
        $request->validate(['days' => ['nullable', 'integer', 'min:7', 'max:60']]);

        $data = $this->reporting->workflowAutomationOverview((int) $request->input('days', 14));

        return response()->json(['data' => $data]);
    }
}
