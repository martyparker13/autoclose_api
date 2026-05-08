<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Deal\StoreDealRequest;
use App\Http\Requests\Deal\SyncFiProductsRequest;
use App\Http\Requests\Deal\TransitionDealRequest;
use App\Http\Requests\Deal\UpdateDealRequest;
use App\Http\Resources\DealResource;
use App\Models\Deal;
use App\Services\DealService;
use App\Services\Integrations\EContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DealController extends BaseController
{
    public function __construct(
        private readonly DealService $service,
        private readonly EContractService $eContracts,
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
     * Return a structured deal jacket / summary for post-close review.
     *
     * GET /deals/{deal}/summary  (buyer)
     */
    public function summary(Request $request, int $deal): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user->isBuyer()) {
            $model = $this->service->getForBuyer($deal, $user);
        } else {
            $dealer = app('current_dealer');
            $model  = $this->service->getForDealer($deal, $dealer);
        }

        $this->authorize('view', $model);

        if (in_array($model->status, ['draft', 'cancelled'], true)) {
            return response()->json(['message' => 'Deal summary is not yet available.'], 422);
        }

        $model->load([
            'vehicle',
            'buyer',
            'dealFiProducts.fiProduct',
            'documents',
            'creditApplication',
            'deliveryAppointment',
            'tradeInAppraisal',
        ]);

        return response()->json([
            'data' => [
                'deal_id'     => $model->id,
                'status'      => $model->status,
                'vehicle'     => $model->vehicle ? [
                    'year'   => $model->vehicle->year,
                    'make'   => $model->vehicle->make,
                    'model'  => $model->vehicle->model,
                    'trim'   => $model->vehicle->trim,
                    'vin'    => $model->vehicle->vin,
                    'stock'  => $model->vehicle->stock_number,
                    'color'  => $model->vehicle->exterior_color,
                ] : null,
                'finance'     => [
                    'sale_price'      => $model->sale_price,
                    'down_payment'    => $model->down_payment,
                    'trade_in_value'  => $model->trade_in_value,
                    'finance_amount'  => $model->finance_amount,
                    'apr'             => $model->apr,
                    'term_months'     => $model->term_months,
                    'monthly_payment' => $model->monthly_payment,
                    'lender'          => $model->lender,
                ],
                'fi_products' => $model->dealFiProducts->map(fn ($fp) => [
                    'name'  => $fp->fiProduct?->name ?? "Product #{$fp->fi_product_id}",
                    'type'  => $fp->fiProduct?->type,
                    'price' => $fp->price,
                ]),
                'documents'   => $model->documents->map(fn ($d) => [
                    'id'             => $d->id,
                    'type'           => $d->type,
                    'docusign_status'=> $d->docusign_status,
                    'signed_at'      => $d->signed_at?->toIso8601String(),
                ]),
                'delivery'    => $model->deliveryAppointment ? [
                    'type'         => $model->deliveryAppointment->type,
                    'scheduled_at' => $model->deliveryAppointment->scheduled_at?->toIso8601String(),
                    'address'      => $model->deliveryAppointment->address,
                    'status'       => $model->deliveryAppointment->status,
                ] : null,
                'credit'      => $model->creditApplication ? [
                    'decision'       => $model->creditApplication->decision,
                    'approved_amount'=> $model->creditApplication->approved_amount,
                    'approved_apr'   => $model->creditApplication->approved_apr,
                    'approved_term'  => $model->creditApplication->approved_term,
                ] : null,
                'created_at'  => $model->created_at->toIso8601String(),
            ],
        ]);
    }

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
     * Manually (re-)push the eContract package to DealerTrack / RouteOne.
     *
     * Allows re-sending from docs_pending or docs_signed status (e.g. if first
     * attempt failed or to resend to a different buyer email address).
     */
    public function pushEContract(Request $request, int $deal): JsonResponse
    {
        $dealer = app('current_dealer');
        $model  = $this->service->getForDealer($deal, $dealer);

        $this->authorize('update', $model);

        if (! in_array($model->status, ['docs_pending', 'docs_signed'], true)) {
            return $this->errorResponse(
                'eContract can only be sent when the deal is in docs_pending or docs_signed status.',
                422
            );
        }

        $this->eContracts->push($dealer, $model);

        $model->refresh();

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
