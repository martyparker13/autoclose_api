<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\CreditApplication\StoreCreditApplicationRequest;
use App\Http\Requests\CreditApplication\UpdateCreditApplicationRequest;
use App\Http\Resources\CreditApplicationResource;
use App\Models\CreditApplication;
use App\Models\Deal;
use App\Services\CreditApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditApplicationController extends BaseController
{
    public function __construct(
        private readonly CreditApplicationService $creditApplications,
    ) {}

    /**
     * Show the credit application for a specific deal.
     * Accessible by the owning buyer or dealer staff.
     */
    public function show(Request $request, int $deal): JsonResponse
    {
        $dealModel   = $this->resolveAuthenticatedDeal($request, $deal);
        $application = $this->creditApplications->getForDeal($dealModel);

        return $this->resourceResponse(new CreditApplicationResource($application));
    }

    /**
     * Submit a credit application for a deal (buyer only).
     * Creates or replaces the existing application.
     */
    public function store(StoreCreditApplicationRequest $request, int $deal): JsonResponse
    {
        /** @var \App\Models\User $buyer */
        $buyer     = $request->user();
        $dealer    = app('current_dealer');
        $dealModel = Deal::where('dealer_id', $dealer->id)
            ->where('buyer_id', $buyer->id)
            ->whereIn('status', ['draft', 'credit_submitted'])
            ->findOrFail($deal);

        $application = $this->creditApplications->submit($dealModel, $buyer, $request->validated());

        return $this->resourceResponse(new CreditApplicationResource($application), 201);
    }

    /**
     * Update credit decision (dealer staff / admin only).
     */
    public function update(UpdateCreditApplicationRequest $request, int $deal, int $creditApp): JsonResponse
    {
        $dealer      = app('current_dealer');
        $dealModel   = Deal::where('dealer_id', $dealer->id)->findOrFail($deal);
        $application = CreditApplication::where('deal_id', $dealModel->id)->findOrFail($creditApp);

        $updated = $this->creditApplications->updateDecision($application, $dealModel, $request->validated());

        return $this->resourceResponse(new CreditApplicationResource($updated));
    }

    /**
     * Resolve the deal record for the authenticated user (buyer or dealer staff).
     */
    private function resolveAuthenticatedDeal(Request $request, int $dealId): Deal
    {
        /** @var \App\Models\User $user */
        $user   = $request->user();
        $dealer = app('current_dealer');

        if ($user->isBuyer()) {
            return Deal::where('buyer_id', $user->id)
                ->where('dealer_id', $dealer->id)
                ->findOrFail($dealId);
        }

        return Deal::where('dealer_id', $dealer->id)->findOrFail($dealId);
    }
}

