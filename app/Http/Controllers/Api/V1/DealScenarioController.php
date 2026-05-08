<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DealScenarioResource;
use App\Models\Deal;
use App\Models\DealScenario;
use App\Services\DeskingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class DealScenarioController extends Controller
{
    public function __construct(private readonly DeskingService $desking) {}

    // ── Dealer / buyer (authenticated) ───────────────────────────────────

    /**
     * GET dealer/deals/{deal}/scenarios
     * List the 3 saved scenarios for a deal.
     */
    public function index(Deal $deal): AnonymousResourceCollection
    {
        $this->authorizeDealAccess($deal);

        return DealScenarioResource::collection(
            $deal->scenarios()->get()
        );
    }

    /**
     * POST dealer/deals/{deal}/scenarios/generate
     * (Re-)generate all 3 scenarios from dealer defaults + current deal terms.
     * Dealer staff / F&I manager action.
     */
    public function generate(Request $request, Deal $deal): AnonymousResourceCollection
    {
        $this->authorizeDealAccess($deal);

        $validated = $request->validate([
            'fi_product_ids'   => 'sometimes|array',
            'fi_product_ids.*' => 'integer|exists:fi_products,id',
        ]);

        $fiIds = $validated['fi_product_ids'] ?? $deal->dealFiProducts()->pluck('fi_product_id')->toArray();

        $scenarios = $this->desking->generateForDeal($deal, $fiIds);

        return DealScenarioResource::collection($scenarios);
    }

    /**
     * PUT dealer/deals/{deal}/scenarios/{scenario}
     * Update a single scenario's inputs and recompute payment.
     */
    public function update(Request $request, Deal $deal, DealScenario $scenario): DealScenarioResource
    {
        $this->authorizeDealAccess($deal);

        if ($scenario->deal_id !== $deal->id) {
            abort(404);
        }

        $validated = $request->validate([
            'term_months'      => 'sometimes|integer|min:12|max:120',
            'down_payment'     => 'sometimes|integer|min:0',
            'fi_product_ids'   => 'sometimes|array',
            'fi_product_ids.*' => 'integer|exists:fi_products,id',
        ]);

        // Resolve inputs from request or fall back to existing values
        $termMonths    = $validated['term_months']  ?? $scenario->term_months;
        $downPayment   = $validated['down_payment'] ?? $scenario->down_payment;
        $fiProductIds  = $validated['fi_product_ids'] ?? $scenario->fi_product_ids ?? [];

        $dealer = $deal->dealer;
        $config = $dealer->desking_config ?? [];
        $apr    = $scenario->apr ?? (float) ($config['default_apr'] ?? 6.9);

        $results = $this->desking->calculate(
            salePrice: $scenario->sale_price,
            downPayment: $downPayment,
            tradeInValue: $deal->trade_in_value ?? 0,
            apr: $apr,
            fiProductIds: $fiProductIds,
            termOverrides: [$scenario->label => $termMonths],
        );

        $data = $results[$scenario->label];
        $scenario->update($data);

        return new DealScenarioResource($scenario->fresh());
    }

    /**
     * POST dealer/deals/{deal}/scenarios/{scenario}/select
     * Mark scenario as selected and write its terms to the deal.
     */
    public function select(Deal $deal, DealScenario $scenario): JsonResponse
    {
        $this->authorizeDealAccess($deal);

        if ($scenario->deal_id !== $deal->id) {
            abort(404);
        }

        $this->desking->selectScenario($scenario);

        return response()->json([
            'message'  => 'Scenario selected and deal terms updated.',
            'scenario' => new DealScenarioResource($scenario->fresh()),
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function authorizeDealAccess(Deal $deal): void
    {
        $user = auth()->user();

        // Buyer: must own the deal
        if ($user->role === 'buyer') {
            if ($deal->buyer_id !== $user->id) {
                abort(403, 'Access denied.');
            }

            return;
        }

        // Dealer staff / admin: must belong to same dealer
        if ($user->dealer_id !== $deal->dealer_id) {
            abort(403, 'Access denied.');
        }
    }
}
