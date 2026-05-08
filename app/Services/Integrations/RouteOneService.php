<?php

namespace App\Services\Integrations;

use App\Models\CreditApplication;
use App\Models\Dealer;
use App\Models\Deal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pushes credit applications to RouteOne via their REST API.
 *
 * Credentials required (stored encrypted on Dealer model):
 *   - dealer_code : RouteOne dealer code
 *   - partner_id  : AutoClose technology partner ID
 *   - api_key     : RouteOne API key
 *
 * Partner access: https://www.routeone.com (Technology Partner Program)
 */
class RouteOneService
{
    private const API_BASE = 'https://api.routeone.net/v1';
    private const TIMEOUT  = 15;

    /**
     * Push a credit application to RouteOne.
     *
     * @return array{success: bool, external_id: string|null, error: string|null}
     */
    public function push(Dealer $dealer, Deal $deal, CreditApplication $app): array
    {
        $creds = $dealer->routeone_credentials;

        if (empty($creds)) {
            return ['success' => false, 'external_id' => null, 'error' => 'No RouteOne credentials configured'];
        }

        try {
            $payload  = $this->buildPayload($creds['dealer_code'], $creds['partner_id'], $deal, $app);
            $response = Http::timeout(self::TIMEOUT)
                ->withHeaders([
                    'X-API-Key'    => $creds['api_key'],
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ])
                ->post(self::API_BASE.'/creditapplication', $payload);

            if ($response->successful()) {
                $externalId = $response->json('applicationId') ?? $response->json('id');
                Log::info('RouteOne: credit app pushed', [
                    'dealer_id'   => $dealer->id,
                    'deal_id'     => $deal->id,
                    'external_id' => $externalId,
                ]);
                return ['success' => true, 'external_id' => (string) $externalId, 'error' => null];
            }

            $errorMsg = $response->json('message') ?? $response->json('error') ?? 'HTTP '.$response->status();
            Log::warning('RouteOne: push rejected', [
                'dealer_id' => $dealer->id,
                'deal_id'   => $deal->id,
                'status'    => $response->status(),
                'body'      => $response->body(),
            ]);
            return ['success' => false, 'external_id' => null, 'error' => $errorMsg];

        } catch (\Throwable $e) {
            Log::error('RouteOne: push exception', [
                'dealer_id' => $dealer->id,
                'deal_id'   => $deal->id,
                'error'     => $e->getMessage(),
            ]);
            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Build the RouteOne credit application payload.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(string $dealerCode, string $partnerId, Deal $deal, CreditApplication $app): array
    {
        $vehicle = $deal->vehicle;
        $buyer   = $deal->buyer;

        return [
            'dealerCode' => $dealerCode,
            'partnerId'  => $partnerId,
            'applicant'  => [
                'firstName'        => $buyer?->first_name ?? '',
                'lastName'         => $buyer?->last_name ?? '',
                'dateOfBirth'      => $app->dob?->format('Y-m-d'),
                'annualIncome'     => (int) round($app->annual_income / 100),
                'employmentStatus' => $app->employment_status,
                'employerName'     => $app->employer_name,
                'employerPhone'    => $app->employer_phone,
                'housingStatus'    => $app->housing_status,
                'monthlyRent'      => (int) round($app->monthly_housing / 100),
                'yearsAtEmployer'  => $app->years_at_employer,
            ],
            'vehicle'    => [
                'vin'       => $vehicle?->vin,
                'year'      => $vehicle?->year,
                'make'      => $vehicle?->make,
                'model'     => $vehicle?->model,
                'trim'      => $vehicle?->trim,
                'odometer'  => $vehicle?->mileage,
                'salePrice' => (int) round($deal->sale_price / 100),
            ],
            'financing'  => [
                'amountFinanced' => (int) round($deal->finance_amount / 100),
                'downPayment'    => (int) round($deal->down_payment / 100),
                'termMonths'     => $deal->term_months,
            ],
        ];
    }
}
