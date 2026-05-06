<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\TradeIn\RespondTradeInRequest;
use App\Http\Requests\TradeIn\StoreTradeInRequest;
use App\Http\Resources\TradeInAppraisalResource;
use App\Models\Deal;
use App\Models\TradeInAppraisal;
use App\Services\TradeInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TradeInAppraisalController extends BaseController
{
    public function __construct(
        private readonly TradeInService $tradeIns,
    ) {}

    /**
     * Submit a trade-in appraisal request for a deal (buyer only).
     * Creates or replaces the existing appraisal on the deal.
     */
    public function store(StoreTradeInRequest $request, int $deal): JsonResponse
    {
        /** @var \App\Models\User $buyer */
        $buyer     = $request->user();
        $dealer    = app('current_dealer');
        $dealModel = Deal::where('dealer_id', $dealer->id)
            ->where('buyer_id', $buyer->id)
            ->findOrFail($deal);

        $appraisal = $this->tradeIns->submit($dealModel, $dealer, $request->validated());

        return $this->resourceResponse(new TradeInAppraisalResource($appraisal), 201);
    }

    /**
     * Show the trade-in appraisal for a deal.
     */
    public function show(Request $request, int $deal): JsonResponse
    {
        $dealer    = app('current_dealer');
        $dealModel = $this->resolveDeal($request, $dealer->id, $deal);
        $appraisal = $this->tradeIns->getForDeal($dealModel);

        return $this->resourceResponse(new TradeInAppraisalResource($appraisal));
    }

    /**
     * Dealer responds with an offer (dealer staff / admin only).
     */
    public function respond(RespondTradeInRequest $request, int $deal, int $appraisal): JsonResponse
    {
        $dealer    = app('current_dealer');
        $dealModel = Deal::where('dealer_id', $dealer->id)->findOrFail($deal);
        $model     = TradeInAppraisal::where('deal_id', $dealModel->id)->findOrFail($appraisal);

        $updated = $this->tradeIns->respond($model, $dealModel, $request->validated());

        return $this->resourceResponse(new TradeInAppraisalResource($updated));
    }

    private function resolveDeal(Request $request, int $dealerId, int $dealId): Deal
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user->isBuyer()) {
            return Deal::where('buyer_id', $user->id)->where('dealer_id', $dealerId)->findOrFail($dealId);
        }

        return Deal::where('dealer_id', $dealerId)->findOrFail($dealId);
    }
}

