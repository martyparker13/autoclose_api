<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Models\Vehicle;
use App\Services\DeskingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stateless payment calculator — no authentication required.
 * Used by buyer-facing vehicle listing & detail pages.
 */
class DeskingCalculatorController extends Controller
{
    public function __construct(private readonly DeskingService $desking) {}

    /**
     * GET /vehicles/{vehicle}/desking-config
     * Return the dealer's placeholder rate and term configuration.
     * Buyers use this to seed the payment calculator widget.
     */
    public function config(Vehicle $vehicle): JsonResponse
    {
        $dealer = Dealer::findOrFail($vehicle->dealer_id);
        $config = $this->desking->dealerConfig($dealer);

        return response()->json(['data' => $config]);
    }

    /**
     * POST /vehicles/{vehicle}/pencil
     * Stateless calculation — returns 3 payment scenarios.
     *
     * Body:
     *   down_payment    integer  cents  (required)
     *   trade_in_value  integer  cents  (optional, default 0)
     *   fi_product_ids  array    int[]  (optional)
     */
    public function pencil(Request $request, Vehicle $vehicle): JsonResponse
    {
        $validated = $request->validate([
            'down_payment'     => 'required|integer|min:0',
            'trade_in_value'   => 'sometimes|integer|min:0',
            'fi_product_ids'   => 'sometimes|array',
            'fi_product_ids.*' => 'integer|exists:fi_products,id',
        ]);

        $salePrice    = $vehicle->internet_price ?? $vehicle->price;
        $downPayment  = $validated['down_payment'];
        $tradeIn      = $validated['trade_in_value'] ?? 0;
        $fiProductIds = $validated['fi_product_ids'] ?? [];

        $dealer = Dealer::findOrFail($vehicle->dealer_id);
        $config = $this->desking->dealerConfig($dealer);
        $apr    = $config['default_apr'];

        $scenarios = $this->desking->calculate(
            salePrice: $salePrice,
            downPayment: $downPayment,
            tradeInValue: $tradeIn,
            apr: $apr,
            fiProductIds: $fiProductIds,
        );

        return response()->json([
            'data' => array_values($scenarios),
            'meta' => [
                'sale_price'   => $salePrice,
                'apr'          => $apr,
                'disclaimer'   => 'Estimated payment only. Your actual rate and payment will be determined after credit approval.',
            ],
        ]);
    }
}
