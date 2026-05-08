<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Services\Integrations\SoftCreditPullService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PreQualController extends Controller
{
    public function __construct(private readonly SoftCreditPullService $service) {}

    /**
     * Run a soft credit pre-qualification for the deal.
     *
     * POST /deals/{deal}/pre-qualify  (buyer)
     */
    public function store(Request $request, int $deal): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $validated = $request->validate([
            'credit_score_range' => ['required', 'string', 'in:excellent,good,fair,poor,unknown'],
        ]);

        $model = Deal::where('buyer_id', $user->id)->findOrFail($deal);

        $this->authorize('view', $model);

        $result = $this->service->estimate($model, $validated['credit_score_range']);

        // Persist to credit application if one exists
        $creditApp = $model->creditApplication;
        if ($creditApp) {
            $creditApp->update(['pre_qual_result' => $result]);
        }

        return response()->json(['data' => $result]);
    }
}
