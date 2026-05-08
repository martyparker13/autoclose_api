<?php

namespace App\Services\Integrations;

use App\Models\Deal;

/**
 * Soft credit pull pre-qualification.
 *
 * Returns an estimated APR and monthly-payment range based on the buyer's
 * self-reported credit-score tier without triggering a hard inquiry.
 *
 * A real implementation can swap the APR band lookup for a 700Credit or
 * DealerSocket soft-pull API call; the contract remains the same.
 */
class SoftCreditPullService
{
    /**
     * APR range bands indexed by credit-score tier (in percent).
     *
     * @var array<string, array{apr_min: float, apr_max: float}>
     */
    private const APR_BANDS = [
        'excellent' => ['apr_min' => 3.9,  'apr_max' => 5.9],   // 720+
        'good'      => ['apr_min' => 5.9,  'apr_max' => 8.9],   // 680-719
        'fair'      => ['apr_min' => 8.9,  'apr_max' => 14.9],  // 620-679
        'poor'      => ['apr_min' => 14.9, 'apr_max' => 21.9],  // <620
        'unknown'   => ['apr_min' => 6.9,  'apr_max' => 12.9],
    ];

    /**
     * Estimate a payment range for the deal based on credit-score tier.
     *
     * @return array{
     *   credit_score_range: string,
     *   estimated_apr_min: float,
     *   estimated_apr_max: float,
     *   term_months: int,
     *   finance_amount: int,
     *   estimated_payment_min: int,
     *   estimated_payment_max: int,
     *   pull_type: string,
     *   note: string,
     * }
     */
    public function estimate(Deal $deal, string $creditScoreRange): array
    {
        $band = self::APR_BANDS[$creditScoreRange] ?? self::APR_BANDS['unknown'];

        $principal  = (int) ($deal->finance_amount
            ?? max(0, ($deal->sale_price ?? 0) - ($deal->down_payment ?? 0)));
        $termMonths = $deal->term_months ?? 60;

        $paymentMin = $this->monthlyPayment($principal, $band['apr_min'] / 100 / 12, $termMonths);
        $paymentMax = $this->monthlyPayment($principal, $band['apr_max'] / 100 / 12, $termMonths);

        return [
            'credit_score_range'    => $creditScoreRange,
            'estimated_apr_min'     => $band['apr_min'],
            'estimated_apr_max'     => $band['apr_max'],
            'term_months'           => $termMonths,
            'finance_amount'        => $principal,
            'estimated_payment_min' => (int) round($paymentMin),
            'estimated_payment_max' => (int) round($paymentMax),
            'pull_type'             => 'soft',
            'note'                  => 'Estimate only — actual rate determined after formal credit review.',
        ];
    }

    /**
     * Standard amortising monthly-payment formula.
     */
    private function monthlyPayment(int $principal, float $monthlyRate, int $months): float
    {
        if ($principal <= 0 || $months <= 0) {
            return 0.0;
        }

        if ($monthlyRate == 0.0) {
            return $principal / $months;
        }

        return $principal * $monthlyRate / (1 - (1 + $monthlyRate) ** -$months);
    }
}
