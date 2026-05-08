<?php

namespace App\Services\Integrations;

use App\Models\Dealer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lender rate feed integration.
 *
 * Supports two modes:
 *  1. Live RouteOne rate feed (when dealer has routeone_credentials configured)
 *  2. Dealer-configured rate bands stored in desking_config['lender_rates']
 *
 * Rate bands format in desking_config:
 *  "lender_rates": [
 *    { "lender": "Ally Financial", "min_score": 720, "max_score": 850, "tiers": { "36": 4.9, "48": 5.4, "60": 5.9, "72": 6.4 } },
 *    { "lender": "Chase Auto",     "min_score": 680, "max_score": 719, "tiers": { "36": 6.5, "48": 6.9, "60": 7.4, "72": 7.9 } },
 *    ...
 *  ]
 */
class LenderRateFeedService
{
    /**
     * Get available lender quotes for a given credit profile.
     *
     * @param  int         $amountCents      financed amount in cents
     * @param  string|null $creditScoreRange  e.g. "720-739", "680-719", "620-679"
     * @param  int         $termMonths        requested term
     * @return list<array{lender: string, apr: float, term_months: int, monthly_payment_cents: int, program: string}>
     */
    public function getRates(
        Dealer $dealer,
        int $amountCents,
        ?string $creditScoreRange,
        int $termMonths,
    ): array {
        $credentials = $dealer->routeone_credentials;

        if (! empty($credentials['api_key']) && ! empty($credentials['dealer_code'])) {
            $live = $this->fetchRouteOneRates($credentials, $amountCents, $creditScoreRange, $termMonths);
            if ($live !== null) {
                return $live;
            }
        }

        return $this->getConfiguredRates($dealer, $amountCents, $creditScoreRange, $termMonths);
    }

    /**
     * Get all lender rate bands configured on the dealer (for the desking settings UI).
     *
     * @return list<array{lender: string, min_score: int, max_score: int, tiers: array<string, float>}>
     */
    public function getRateBands(Dealer $dealer): array
    {
        $config = $dealer->desking_config ?? [];

        return $config['lender_rates'] ?? $this->defaultBands();
    }

    /**
     * Persist lender rate bands to dealer desking config.
     *
     * @param  list<array{lender: string, min_score: int, max_score: int, tiers: array<string, float>}>  $bands
     */
    public function saveRateBands(Dealer $dealer, array $bands): void
    {
        $config                  = $dealer->desking_config ?? [];
        $config['lender_rates']  = $bands;

        $dealer->update(['desking_config' => $config]);
    }

    // ── Private helpers ───────────────────────────────────────────────────

    /**
     * Attempt to fetch real-time rates from the RouteOne rate feed API.
     * Returns null on any error so the caller can fall back to config.
     *
     * @param  array<string, mixed>  $credentials
     * @return list<array{lender: string, apr: float, term_months: int, monthly_payment_cents: int, program: string}>|null
     */
    private function fetchRouteOneRates(
        array $credentials,
        int $amountCents,
        ?string $creditScoreRange,
        int $termMonths,
    ): ?array {
        try {
            $response = Http::withHeaders([
                'X-RouteOne-DealerCode' => $credentials['dealer_code'],
                'X-RouteOne-ApiKey'     => $credentials['api_key'],
                'Accept'                => 'application/json',
            ])->timeout(5)->post('https://api.routeone.net/v1/rate-feed', [
                'amount'            => $amountCents / 100,
                'term_months'       => $termMonths,
                'credit_tier'       => $this->mapCreditRange($creditScoreRange),
            ]);

            if (! $response->successful()) {
                return null;
            }

            $lenders = $response->json('lenders', []);

            return array_map(fn ($l) => [
                'lender'                 => $l['lender_name'],
                'apr'                    => (float) $l['apr'],
                'term_months'            => (int) $l['term_months'],
                'monthly_payment_cents'  => (int) round($this->monthlyPayment($amountCents, (float) $l['apr'], (int) $l['term_months'])),
                'program'                => $l['program_name'] ?? 'Standard',
            ], $lenders);

        } catch (\Throwable $e) {
            Log::warning('RouteOne rate feed error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Build quotes from dealer-configured rate bands.
     *
     * @return list<array{lender: string, apr: float, term_months: int, monthly_payment_cents: int, program: string}>
     */
    private function getConfiguredRates(
        Dealer $dealer,
        int $amountCents,
        ?string $creditScoreRange,
        int $termMonths,
    ): array {
        $bands     = $this->getRateBands($dealer);
        $minScore  = $this->minScoreFromRange($creditScoreRange);

        $quotes = [];

        foreach ($bands as $band) {
            $bandMin = (int) ($band['min_score'] ?? 0);
            $bandMax = (int) ($band['max_score'] ?? 999);

            if ($minScore !== null && ($minScore < $bandMin || $minScore > $bandMax)) {
                continue;
            }

            $tiers = (array) ($band['tiers'] ?? []);
            $key   = (string) $termMonths;

            if (! isset($tiers[$key])) {
                // Use nearest available term
                ksort($tiers);
                $key = (string) array_key_first($tiers);
            }

            $apr = (float) $tiers[$key];

            $quotes[] = [
                'lender'                 => $band['lender'],
                'apr'                    => $apr,
                'term_months'            => $termMonths,
                'monthly_payment_cents'  => (int) round($this->monthlyPayment($amountCents, $apr, $termMonths)),
                'program'                => 'Standard',
            ];
        }

        // Sort by APR ascending (best rate first)
        usort($quotes, fn ($a, $b) => $a['apr'] <=> $b['apr']);

        return $quotes;
    }

    private function monthlyPayment(int $amountCents, float $apr, int $termMonths): float
    {
        if ($apr <= 0) {
            return $amountCents / $termMonths;
        }

        $r = ($apr / 100) / 12;

        return $amountCents * ($r * (1 + $r) ** $termMonths) / ((1 + $r) ** $termMonths - 1);
    }

    private function mapCreditRange(?string $range): string
    {
        return match (true) {
            str_starts_with((string) $range, '72'), str_starts_with((string) $range, '75'),
            str_starts_with((string) $range, '78'), str_starts_with((string) $range, '80') => 'tier1',
            str_starts_with((string) $range, '68'), str_starts_with((string) $range, '69'),
            str_starts_with((string) $range, '70') => 'tier2',
            str_starts_with((string) $range, '62'), str_starts_with((string) $range, '64'),
            str_starts_with((string) $range, '66') => 'tier3',
            default => 'tier4',
        };
    }

    private function minScoreFromRange(?string $range): ?int
    {
        if (! $range) {
            return null;
        }

        // Formats: "720-739", "720+", "720"
        return (int) preg_replace('/[^0-9].*/', '', $range);
    }

    /**
     * Default rate bands when dealer hasn't configured any.
     *
     * @return list<array{lender: string, min_score: int, max_score: int, tiers: array<string, float>}>
     */
    private function defaultBands(): array
    {
        return [
            [
                'lender'    => 'Ally Financial',
                'min_score' => 720,
                'max_score' => 850,
                'tiers'     => ['36' => 4.9, '48' => 5.4, '60' => 5.9, '72' => 6.4],
            ],
            [
                'lender'    => 'Chase Auto Finance',
                'min_score' => 680,
                'max_score' => 719,
                'tiers'     => ['36' => 6.5, '48' => 6.9, '60' => 7.4, '72' => 7.9],
            ],
            [
                'lender'    => 'Capital One Auto',
                'min_score' => 620,
                'max_score' => 679,
                'tiers'     => ['36' => 9.9, '48' => 10.4, '60' => 10.9, '72' => 11.4],
            ],
            [
                'lender'    => 'Westlake Financial',
                'min_score' => 500,
                'max_score' => 619,
                'tiers'     => ['36' => 15.9, '48' => 16.4, '60' => 16.9, '72' => 17.4],
            ],
        ];
    }
}
