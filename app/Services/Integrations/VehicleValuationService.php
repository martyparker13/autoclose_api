<?php

namespace App\Services\Integrations;

use App\Models\Dealer;
use App\Models\TradeInAppraisal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Trade-in valuation service.
 *
 * Supports two modes:
 *  1. Live KBB Instant Cash Offer API (when dealer has kbb_credentials configured in desking_config)
 *  2. Manheim Market Report-style estimated value based on vehicle age, mileage, and condition
 *
 * To connect KBB, store in dealer.desking_config:
 *   "kbb_api_key": "xxx",  "kbb_partner_id": "yyy"
 *
 * To connect Manheim MMR, store:
 *   "manheim_api_key": "xxx"
 */
class VehicleValuationService
{
    /**
     * Run a valuation for the given appraisal record and persist the results.
     *
     * @return array{kbb_value: int|null, black_book_value: int|null, source: string}
     */
    public function valuate(Dealer $dealer, TradeInAppraisal $appraisal): array
    {
        $config = $dealer->desking_config ?? [];

        // 1 — Try KBB if credentials present
        if (! empty($config['kbb_api_key'])) {
            $kbb = $this->fetchKbbValue($config, $appraisal);
            if ($kbb !== null) {
                $appraisal->update(['kbb_value' => $kbb]);

                return ['kbb_value' => $kbb, 'black_book_value' => null, 'source' => 'kbb'];
            }
        }

        // 2 — Try Manheim MMR if credentials present
        if (! empty($config['manheim_api_key'])) {
            $mmr = $this->fetchManheimMmr($config, $appraisal);
            if ($mmr !== null) {
                $appraisal->update(['black_book_value' => $mmr]);

                return ['kbb_value' => null, 'black_book_value' => $mmr, 'source' => 'manheim'];
            }
        }

        // 3 — Fall back to algorithmic estimate
        $estimated = $this->algorithmicEstimate($appraisal);
        $appraisal->update(['kbb_value' => $estimated]);

        return ['kbb_value' => $estimated, 'black_book_value' => null, 'source' => 'estimated'];
    }

    // ── Private helpers ───────────────────────────────────────────────────

    /**
     * Call the KBB Instant Cash Offer API.
     * Docs: https://developer.kbb.com/instant-cash-offer
     *
     * @param  array<string, mixed>  $config
     */
    private function fetchKbbValue(array $config, TradeInAppraisal $appraisal): ?int
    {
        try {
            $response = Http::withHeaders([
                'X-KBB-ApiKey'     => $config['kbb_api_key'],
                'X-KBB-PartnerId'  => $config['kbb_partner_id'] ?? '',
                'Accept'           => 'application/json',
            ])->timeout(8)->post('https://api.kbb.com/v3/instant-cash-offer/estimate', [
                'vin'       => $appraisal->vin,
                'mileage'   => $appraisal->mileage,
                'condition' => $this->mapCondition($appraisal->condition),
                'zip_code'  => $config['dealer_zip'] ?? '90001',
            ]);

            if (! $response->successful()) {
                return null;
            }

            $valueCents = (int) round($response->json('offer_amount', 0) * 100);

            return $valueCents > 0 ? $valueCents : null;

        } catch (\Throwable $e) {
            Log::warning('KBB valuation error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Call the Manheim Market Report (MMR) API.
     * Docs: https://developer.manheim.com/mmr
     *
     * @param  array<string, mixed>  $config
     */
    private function fetchManheimMmr(array $config, TradeInAppraisal $appraisal): ?int
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$config['manheim_api_key'],
                'Accept'        => 'application/json',
            ])->timeout(8)->get('https://api.manheim.com/mmr/vin/'.$appraisal->vin, [
                'mileage'   => $appraisal->mileage,
                'condition' => $this->mapCondition($appraisal->condition),
            ]);

            if (! $response->successful()) {
                return null;
            }

            $averageWholesale = $response->json('wholesale.average', 0);
            $valueCents       = (int) round($averageWholesale * 100);

            return $valueCents > 0 ? $valueCents : null;

        } catch (\Throwable $e) {
            Log::warning('Manheim MMR error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Algorithmic fallback estimate based on MSRP depreciation curves.
     *
     * Very simplified model — real dealers should connect KBB or Manheim.
     * Uses straight-line depreciation + mileage penalty + condition adjustment.
     */
    private function algorithmicEstimate(TradeInAppraisal $appraisal): int
    {
        // Baseline: assume $25,000 new-car average if no price data
        $baseCents = 2_500_000;

        $currentYear = (int) date('Y');
        $vehicleYear = $appraisal->year ?? $currentYear;
        $age         = max(0, $currentYear - $vehicleYear);
        $mileage     = $appraisal->mileage ?? 12000;

        // Age depreciation: 15% first year, 10% per year after, floored at 20% of base
        $ageMultiplier = match (true) {
            $age === 0 => 1.0,
            $age === 1 => 0.85,
            $age === 2 => 0.76,
            $age === 3 => 0.68,
            $age === 4 => 0.60,
            $age === 5 => 0.54,
            $age <= 8  => 0.54 - (($age - 5) * 0.05),
            default    => 0.20,
        };

        // Mileage penalty: -$0.06/mile over 12k/year average
        $expectedMiles = $age * 12_000;
        $excessMiles   = max(0, $mileage - $expectedMiles);
        $mileagePenalty = $excessMiles * 6; // 6 cents per excess mile

        // Condition adjustment
        $conditionMultiplier = match ($appraisal->condition) {
            'excellent' => 1.10,
            'good'      => 1.00,
            'fair'      => 0.88,
            'poor'      => 0.72,
            default     => 0.95,
        };

        $estimated = (int) round(
            ($baseCents * $ageMultiplier * $conditionMultiplier) - $mileagePenalty
        );

        return max(50000, $estimated); // floor at $500
    }

    private function mapCondition(?string $condition): string
    {
        return match ($condition) {
            'excellent' => 'Excellent',
            'good'      => 'Good',
            'fair'      => 'Fair',
            'poor'      => 'Poor',
            default     => 'Good',
        };
    }
}
