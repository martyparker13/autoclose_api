<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Deal\StoreDealRequest;
use App\Http\Requests\Deal\SyncFiProductsRequest;
use App\Http\Requests\Deal\TransitionDealRequest;
use App\Http\Requests\Deal\UpdateDealRequest;
use App\Http\Resources\DealResource;
use App\Models\Deal;
use App\Services\DealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DealController extends BaseController
{
    public function __construct(
        private readonly DealService $service,
    ) {}

    /**
     * List deals — dealers see all their deals, buyers see their own.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user->isBuyer()) {
            $paginator = $this->service->listForBuyer($user, $request->only(['status', 'cursor']));
        } else {
            $dealer = app('current_dealer');
            $paginator = $this->service->listForDealer($dealer, $request->only(['status', 'cursor']));
        }

        return DealResource::collection($paginator);
    }

    /**
     * Show a single deal.
     */
    public function show(Request $request, int $deal): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user->isBuyer()) {
            $model = $this->service->getForBuyer($deal, $user);
        } else {
            $dealer = app('current_dealer');
            $model = $this->service->getForDealer($deal, $dealer);
        }

        $this->authorize('view', $model);

        return $this->resourceResponse(new DealResource($model));
    }

    /**
     * Open a new deal (buyer initiates purchase).
     */
    public function store(StoreDealRequest $request): JsonResponse
    {
        $this->authorize('create', Deal::class);

        /** @var \App\Models\User $user */
        $user = $request->user();
        $dealer = app('current_dealer');

        $deal = $this->service->open($dealer, $user, $request->validated());

        return $this->resourceResponse(new DealResource($deal), 201);
    }

    /**
     * Update deal terms (sale price, down payment, trade-in, financing).
     */
    public function update(UpdateDealRequest $request, int $deal): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user->isBuyer()) {
            $model = $this->service->getForBuyer($deal, $user);
        } else {
            $dealer = app('current_dealer');
            $model = $this->service->getForDealer($deal, $dealer);
        }

        $this->authorize('update', $model);

        $dealer = app('current_dealer');
        $model = $this->service->updateTerms($model, $dealer, $request->validated());

        return $this->resourceResponse(new DealResource($model));
    }

    /**
     * Transition deal status (dealer staff/admin only).
     */
    public function transition(TransitionDealRequest $request, int $deal): JsonResponse
    {
        $dealer = app('current_dealer');
        $model = $this->service->getForDealer($deal, $dealer);

        $this->authorize('update', $model);

        $model = $this->service->transition($model, $dealer, $request->validated()['status']);

        return $this->resourceResponse(new DealResource($model));
    }

    /**
     * Sync F&I products on a deal (dealer staff/admin only).
     */
    public function syncFiProducts(SyncFiProductsRequest $request, int $deal): JsonResponse
    {
        $dealer = app('current_dealer');
        $model = $this->service->getForDealer($deal, $dealer);

        $this->authorize('update', $model);

        $model = $this->service->syncFiProducts($model, $dealer, $request->validated()['products']);

        return $this->resourceResponse(new DealResource($model));
    }

    /**
     * Cancel / delete a deal (dealer admin only).
     */
    public function destroy(Request $request, int $deal): JsonResponse
    {
        $dealer = app('current_dealer');
        $model = $this->service->getForDealer($deal, $dealer);

        $this->authorize('delete', $model);

        $this->service->cancel($model, $dealer);

        return $this->noContent();
    }
}
