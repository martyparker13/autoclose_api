<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\DealDocumentResource;
use App\Models\Deal;
use App\Models\DealDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealDocumentController extends BaseController
{
    /**
     * List all documents for a deal.
     */
    public function index(Request $request, int $deal): JsonResponse
    {
        $dealer    = app('current_dealer');
        $dealModel = $this->resolveDeal($request, $dealer->id, $deal);
        $documents = DealDocument::where('deal_id', $dealModel->id)->get();

        return response()->json(['data' => DealDocumentResource::collection($documents)]);
    }

    /**
     * Show a single document.
     */
    public function show(Request $request, int $deal, int $document): JsonResponse
    {
        $dealer    = app('current_dealer');
        $dealModel = $this->resolveDeal($request, $dealer->id, $deal);
        $doc       = DealDocument::where('deal_id', $dealModel->id)->findOrFail($document);

        return $this->resourceResponse(new DealDocumentResource($doc));
    }

    /**
     * Upload a document to a deal (dealer staff).
     */
    public function store(Request $request, int $deal): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'in:buyers_order,retail_installment,title_app,odometer,we_owe,insurance_proof,id_verification,income_verification'],
            'file' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $dealer    = app('current_dealer');
        $dealModel = Deal::where('dealer_id', $dealer->id)->findOrFail($deal);

        $path = $request->file('file')->store("deals/{$dealModel->id}/documents", 's3');

        /** @var \App\Models\User $user */
        $user = $request->user();

        $doc = DealDocument::create([
            'deal_id'     => $dealModel->id,
            'type'        => $request->input('type'),
            's3_path'     => $path,
            'uploaded_by' => $user->id,
        ]);

        return $this->resourceResponse(new DealDocumentResource($doc), 201);
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
