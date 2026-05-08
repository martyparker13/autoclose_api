<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DealScenario;
use App\Models\Dealer;
use App\Models\FiProduct;
use Illuminate\Support\Collection;

class DeskingService
{
    /**
     * Default APR used when dealer has no config.
     */
    private const DEFAULT_APR = 6.9;

    /**
     * Default term buckets (months) for Good / Better / Best.
     * Good  = longest term  (lowest monthly, most interest paid)
     * Better = mid term
     * Best  = shortest term (highest monthly, least interest paid)
     */
    private const DEFAULT_TERMS = [
        'good'   => 72,
        'better' => 60,
        'best'   => 48,
    ];

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Stateless calculation — returns 3 scenario arrays without persisting anything.
     *
     * @param  int    $salePrice      cents
     * @param  int    $downPayment    cents
     * @param  int    $tradeInValue   cents (reduces finance amount)
     * @param  float  $apr            annual percentage rate (e.g. 6.9)
     * @param  array<int>  $fiProductIds  products to include
     * @param  array<string,int>  $termOverrides  override any label term, e.g. ['good'=>84]
     * @return array<string, array<string, mixed>>  keyed by label
     */
    public function calculate(
        int $salePrice,
        int $downPayment,
        int $tradeInValue,
        float $apr,
        array $fiProductIds = [],
        array $termOverrides = [],
    ): array {
        $fiTotal = $this->sumFiProducts($fiProductIds);

        $terms = array_merge(self::DEFAULT_TERMS, $termOverrides);

        $results = [];
        foreach ($terms as $label => $termMonths) {
            $results[$label] = $this->computeScenario(
                label: $label,
                salePrice: $salePrice,
                downPayment: $downPayment,
                tradeInValue: $tradeInValue,
                fiTotal: $fiTotal,
                fiProductIds: $fiProductIds,
                apr: $apr,
                termMonths: $termMonths,
            );
        }

        return $results;
    }

    /**
     * Generate 3 scenarios for a deal using the dealer's configured defaults and
     * persist them (upsert by deal_id + label). Returns the saved models.
     *
     * @param  array<int>  $fiProductIds
     * @return Collection<int, DealScenario>
     */
    public function generateForDeal(Deal $deal, array $fiProductIds = []): Collection
    {
        $dealer = $deal->dealer;
        $config = $dealer->desking_config ?? [];

        $apr  = (float) ($config['default_apr'] ?? self::DEFAULT_APR);
        $termOverrides = $this->termOverridesFromConfig($config);

        $salePrice    = $deal->sale_price    ?? 0;
        $downPayment  = $deal->down_payment  ?? 0;
        $tradeInValue = $deal->trade_in_value ?? 0;

        $scenarios = $this->calculate(
            salePrice: $salePrice,
            downPayment: $downPayment,
            tradeInValue: $tradeInValue,
            apr: $apr,
            fiProductIds: $fiProductIds,
            termOverrides: $termOverrides,
        );

        foreach ($scenarios as $data) {
            DealScenario::updateOrCreate(
                ['deal_id' => $deal->id, 'label' => $data['label']],
                $data,
            );
        }

        return $deal->scenarios()->get();
    }

    /**
     * Select a scenario: marks it as selected, deselects the others,
     * and applies its terms back to the deal.
     */
    public function selectScenario(DealScenario $scenario): void
    {
        $deal = $scenario->deal;

        // Deselect all siblings
        DealScenario::where('deal_id', $deal->id)->update(['is_selected' => false]);
        $scenario->update(['is_selected' => true]);

        // Write chosen terms back to deal
        $deal->update([
            'term_months'     => $scenario->term_months,
            'down_payment'    => $scenario->down_payment,
            'apr'             => $scenario->apr,
            'monthly_payment' => $scenario->monthly_payment,
            'finance_amount'  => max(0, $scenario->sale_price - $scenario->down_payment - ($deal->trade_in_value ?? 0)),
        ]);
    }

    /**
     * Get the dealer's desking config resolved to its public shape.
     *
     * @return array{default_apr: float, terms: array{good: int, better: int, best: int}}
     */
    public function dealerConfig(Dealer $dealer): array
    {
        $config = $dealer->desking_config ?? [];
        $terms  = $this->termOverridesFromConfig($config);

        return [
            'default_apr' => (float) ($config['default_apr'] ?? self::DEFAULT_APR),
            'terms'       => array_merge(self::DEFAULT_TERMS, $terms),
        ];
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * Compute a single scenario array.
     *
     * @param  array<int>  $fiProductIds
     * @return array<string, mixed>
     */
    private function computeScenario(
        string $label,
        int $salePrice,
        int $downPayment,
        int $tradeInValue,
        int $fiTotal,
        array $fiProductIds,
        float $apr,
        int $termMonths,
    ): array {
        // Principal = (sale + F&I) - down - trade-in, minimum 0
        $principal = max(0, $salePrice + $fiTotal - $downPayment - $tradeInValue);

        $monthly = $this->amortize($principal, $apr, $termMonths);
        $total   = (int) round($monthly * $termMonths + $downPayment);

        return [
            'label'           => $label,
            'term_months'     => $termMonths,
            'down_payment'    => $downPayment,
            'sale_price'      => $salePrice,
            'fi_product_ids'  => $fiProductIds,
            'apr'             => $apr,
            'monthly_payment' => (int) round($monthly),
            'total_cost'      => $total,
            'is_selected'     => false,
        ];
    }

    /**
     * Standard amortization formula: M = P[r(1+r)^n] / [(1+r)^n - 1]
     * Returns monthly payment in the same unit as principal.
     */
    private function amortize(int $principal, float $apr, int $termMonths): float
    {
        if ($principal <= 0) {
            return 0.0;
        }

        $r = $apr / 100 / 12; // monthly rate

        if ($r == 0.0) {
            return $principal / $termMonths;
        }

        $factor = pow(1 + $r, $termMonths);

        return ($principal * $r * $factor) / ($factor - 1);
    }

    /**
     * Sum the price of the given F&I products (in cents).
     *
     * @param  array<int>  $fiProductIds
     */
    private function sumFiProducts(array $fiProductIds): int
    {
        if (empty($fiProductIds)) {
            return 0;
        }

        return (int) FiProduct::whereIn('id', $fiProductIds)->sum('price');
    }

    /**
     * Extract per-label term overrides from the dealer desking_config JSON.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, int>
     */
    private function termOverridesFromConfig(array $config): array
    {
        $overrides = [];
        foreach (['good', 'better', 'best'] as $label) {
            $key = "{$label}_term";
            if (isset($config[$key]) && is_numeric($config[$key])) {
                $overrides[$label] = (int) $config[$key];
            }
        }

        return $overrides;
    }
}
