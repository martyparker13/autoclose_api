<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Integrations\LenderRateFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LenderRatesController extends Controller
{
    public function __construct(
        private readonly LenderRateFeedService $feed,
    ) {}

    /**
     * GET /dealer/lender-rates
     *
     * Return live lender quotes for the given financing parameters.
     * If credit_score_range is omitted, all configured lenders are returned.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'amount'             => ['required', 'integer', 'min:1'],
            'term_months'        => ['required', 'integer', 'in:24,36,48,60,72,84'],
            'credit_score_range' => ['nullable', 'string', 'max:20'],
        ]);

        $dealer = app('current_dealer');

        $quotes = $this->feed->getRates(
            dealer:           $dealer,
            amountCents:      (int) $request->input('amount'),
            creditScoreRange: $request->input('credit_score_range'),
            termMonths:       (int) $request->input('term_months'),
        );

        return response()->json(['data' => $quotes]);
    }

    /**
     * GET /dealer/lender-rate-bands
     *
     * Return the full configured rate band matrix for the dealer settings UI.
     */
    public function bands(Request $request): JsonResponse
    {
        $dealer = app('current_dealer');
        $bands  = $this->feed->getRateBands($dealer);

        return response()->json(['data' => $bands]);
    }

    /**
     * PUT /dealer/lender-rate-bands
     *
     * Save the lender rate band matrix (dealer admin only).
     */
    public function updateBands(Request $request): JsonResponse
    {
        $request->validate([
            'bands'                      => ['required', 'array', 'min:1'],
            'bands.*.lender'             => ['required', 'string', 'max:100'],
            'bands.*.min_score'          => ['required', 'integer', 'min:300', 'max:850'],
            'bands.*.max_score'          => ['required', 'integer', 'min:300', 'max:850'],
            'bands.*.tiers'              => ['required', 'array'],
            'bands.*.tiers.*'            => ['required', 'numeric', 'min:0', 'max:40'],
        ]);

        $dealer = app('current_dealer');
        $this->feed->saveRateBands($dealer, $request->input('bands'));

        return response()->json(['data' => $this->feed->getRateBands($dealer)]);
    }
}
