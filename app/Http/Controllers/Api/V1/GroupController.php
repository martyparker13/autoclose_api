<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\DealerGroupResource;
use App\Http\Resources\DealerResource;
use App\Models\Deal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupController extends BaseController
{
    /**
     * GET /group
     * Return the authenticated group admin's group with its dealers.
     */
    public function show(Request $request): JsonResponse
    {
        $user  = $request->user();
        $group = $user->dealerGroup()->with('dealers')->withCount('dealers')->firstOrFail();

        return response()->json([
            'data' => array_merge(
                (new DealerGroupResource($group))->resolve(),
                ['dealers' => DealerResource::collection($group->dealers)],
            ),
        ]);
    }

    /**
     * GET /group/reports/summary
     * Aggregate deal counts and revenue across all dealers in the group.
     */
    public function reportSummary(Request $request): JsonResponse
    {
        $user  = $request->user();
        $group = $user->dealerGroup()->firstOrFail();

        $dealerIds = $group->dealers()->pluck('id');

        $deals = Deal::whereIn('dealer_id', $dealerIds)
            ->selectRaw('
                COUNT(*) as total_deals,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as closed_deals,
                SUM(CASE WHEN status = ? THEN sale_price_cents ELSE 0 END) as revenue_cents,
                SUM(CASE WHEN status = ? THEN fi_total_cents ELSE 0 END) as fi_income_cents
            ', ['closed', 'closed', 'closed'])
            ->first();

        $byDealer = Deal::whereIn('dealer_id', $dealerIds)
            ->selectRaw('dealer_id, COUNT(*) as total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as closed', ['closed'])
            ->groupBy('dealer_id')
            ->with('dealer:id,name,slug')
            ->get()
            ->map(fn ($row) => [
                'dealer_id'   => $row->dealer_id,
                'dealer_name' => $row->dealer?->name,
                'total_deals' => $row->total,
                'closed_deals'=> $row->closed,
            ]);

        return response()->json([
            'data' => [
                'group_id'       => $group->id,
                'group_name'     => $group->name,
                'total_deals'    => (int) ($deals->total_deals ?? 0),
                'closed_deals'   => (int) ($deals->closed_deals ?? 0),
                'revenue_cents'  => (int) ($deals->revenue_cents ?? 0),
                'fi_income_cents'=> (int) ($deals->fi_income_cents ?? 0),
                'by_dealer'      => $byDealer,
            ],
        ]);
    }

    /**
     * PATCH /group/active-dealer
     * Set the active dealer context for the current group admin session.
     * The frontend stores this and sends X-Dealer-Id on subsequent requests.
     * This endpoint simply validates that the dealer belongs to the group.
     */
    public function setActiveDealer(Request $request): JsonResponse
    {
        $data  = $request->validate(['dealer_id' => ['required', 'integer']]);
        $user  = $request->user();
        $group = $user->dealerGroup()->firstOrFail();

        $dealer = $group->dealers()->findOrFail($data['dealer_id']);

        return response()->json([
            'data' => new DealerResource($dealer),
        ]);
    }
}
