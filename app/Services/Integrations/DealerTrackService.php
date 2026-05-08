<?php

namespace App\Services\Integrations;

use App\Models\CreditApplication;
use App\Models\Dealer;
use App\Models\Deal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pushes credit applications to DealerTrack via Cox Automotive's OAuth2 API.
 *
 * Credentials required (stored encrypted on Dealer model):
 *   - dealer_id     : Cox-assigned rooftop identifier
 *   - client_id     : OAuth client ID
 *   - client_secret : OAuth client secret
 *
 * Partner access: https://developer.coxautoinc.com
 */
class DealerTrackService
{
    private const TOKEN_URL  = 'https://auth.coxautoinc.com/oauth2/token';
    private const API_BASE   = 'https://api.coxautoinc.com/dealertrack/v1';
    private const SCOPE      = 'dealertrack.creditapplication.write';
    private const TIMEOUT    = 15;

    /**
     * Push a credit application to DealerTrack.
     *
     * @return array{success: bool, external_id: string|null, error: string|null}
     */
    public function push(Dealer $dealer, Deal $deal, CreditApplication $app): array
    {
        $creds = $dealer->dealertrack_credentials;

        if (empty($creds)) {
            return ['success' => false, 'external_id' => null, 'error' => 'No DealerTrack credentials configured'];
        }

        try {
            $token = $this->fetchToken($creds['client_id'], $creds['client_secret']);
        } catch (\Throwable $e) {
            Log::warning('DealerTrack: token fetch failed', [
                'dealer_id' => $dealer->id,
                'error'     => $e->getMessage(),
            ]);
            return ['success' => false, 'external_id' => null, 'error' => 'Authentication failed: '.$e->getMessage()];
        }

        try {
            $payload  = $this->buildPayload($creds['dealer_id'], $deal, $app);
            $response = Http::timeout(self::TIMEOUT)
                ->withToken($token)
                ->post(self::API_BASE.'/creditapplications', $payload);

            if ($response->successful()) {
                $externalId = $response->json('applicationId') ?? $response->json('id');
                Log::info('DealerTrack: credit app pushed', [
                    'dealer_id'      => $dealer->id,
                    'deal_id'        => $deal->id,
                    'external_id'    => $externalId,
                ]);
                return ['success' => true, 'external_id' => (string) $externalId, 'error' => null];
            }

            $errorMsg = $response->json('message') ?? $response->json('error') ?? 'HTTP '.$response->status();
            Log::warning('DealerTrack: push rejected', [
                'dealer_id' => $dealer->id,
                'deal_id'   => $deal->id,
                'status'    => $response->status(),
                'body'      => $response->body(),
            ]);
            return ['success' => false, 'external_id' => null, 'error' => $errorMsg];

        } catch (\Throwable $e) {
            Log::error('DealerTrack: push exception', [
                'dealer_id' => $dealer->id,
                'deal_id'   => $deal->id,
                'error'     => $e->getMessage(),
            ]);
            return ['success' => false, 'external_id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Acquire an OAuth2 access token via client credentials grant.
     *
     * @throws \RuntimeException
     */
    private function fetchToken(string $clientId, string $clientSecret): string
    {
        $response = Http::timeout(self::TIMEOUT)
            ->asForm()
            ->post(self::TOKEN_URL, [
                'grant_type'    => 'client_credentials',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'scope'         => self::SCOPE,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Token request failed with status '.$response->status());
        }

        $token = $response->json('access_token');

        if (empty($token)) {
            throw new \RuntimeException('Token response missing access_token');
        }

        return $token;
    }

    /**
     * Build the DealerTrack credit application payload.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(string $dealertrackDealerId, Deal $deal, CreditApplication $app): array
    {
        $vehicle = $deal->vehicle;
        $buyer   = $deal->buyer;

        return [
            'dealerId'  => $dealertrackDealerId,
            'applicant' => [
                'firstName'        => $buyer?->first_name ?? '',
                'lastName'         => $buyer?->last_name ?? '',
                'dateOfBirth'      => $app->dob?->format('Y-m-d'),
                'annualIncome'     => (int) round($app->annual_income / 100),
                'employmentStatus' => $app->employment_status,
                'employerName'     => $app->employer_name,
                'employerPhone'    => $app->employer_phone,
                'housingStatus'    => $app->housing_status,
                'monthlyHousing'   => (int) round($app->monthly_housing / 100),
                'yearsAtEmployer'  => $app->years_at_employer,
            ],
            'vehicle' => [
                'vin'       => $vehicle?->vin,
                'year'      => $vehicle?->year,
                'make'      => $vehicle?->make,
                'model'     => $vehicle?->model,
                'trim'      => $vehicle?->trim,
                'mileage'   => $vehicle?->mileage,
                'salePrice' => (int) round($deal->sale_price / 100),
            ],
            'deal' => [
                'financeAmount' => (int) round($deal->finance_amount / 100),
                'downPayment'   => (int) round($deal->down_payment / 100),
                'termMonths'    => $deal->term_months,
            ],
        ];
    }
}
