<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\DealerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DealerIntegrationController extends BaseController
{
    /** Supported integration platforms and their credential schemas */
    private const PLATFORMS = ['dealertrack', 'routeone'];

    private const CREDENTIAL_COLUMN = [
        'dealertrack' => 'dealertrack_credentials',
        'routeone'    => 'routeone_credentials',
    ];

    /**
     * GET dealer/settings/integrations/{platform}
     *
     * Returns connection status and masked credential hints for the given platform.
     * Raw secrets are never returned.
     */
    public function show(string $platform): JsonResponse
    {
        $this->validatePlatform($platform);

        $dealer = app('current_dealer');
        $column = self::CREDENTIAL_COLUMN[$platform];
        $creds  = $dealer->{$column};

        return response()->json([
            'data' => [
                'platform'  => $platform,
                'connected' => ! empty($creds),
                'hints'     => $this->maskCredentials($platform, $creds),
            ],
        ]);
    }

    /**
     * PATCH dealer/settings/integrations/{platform}
     *
     * Save (or replace) credentials for the given platform.
     */
    public function update(Request $request, string $platform): JsonResponse
    {
        $this->validatePlatform($platform);

        $rules = match ($platform) {
            'dealertrack' => [
                'dealer_id'     => ['required', 'string', 'max:100'],
                'client_id'     => ['required', 'string', 'max:255'],
                'client_secret' => ['required', 'string', 'max:255'],
            ],
            'routeone' => [
                'dealer_code'   => ['required', 'string', 'max:100'],
                'api_key'       => ['required', 'string', 'max:255'],
                'partner_id'    => ['required', 'string', 'max:100'],
            ],
        };

        $data   = $request->validate($rules);
        $dealer = app('current_dealer');
        $column = self::CREDENTIAL_COLUMN[$platform];

        $dealer->update([$column => $data]);

        return response()->json([
            'data' => [
                'platform'  => $platform,
                'connected' => true,
                'hints'     => $this->maskCredentials($platform, $data),
            ],
        ]);
    }

    /**
     * DELETE dealer/settings/integrations/{platform}
     *
     * Remove stored credentials and disconnect the integration.
     */
    public function disconnect(string $platform): JsonResponse
    {
        $this->validatePlatform($platform);

        $dealer = app('current_dealer');
        $column = self::CREDENTIAL_COLUMN[$platform];

        $dealer->update([$column => null]);

        return response()->json([
            'data' => [
                'platform'  => $platform,
                'connected' => false,
                'hints'     => null,
            ],
        ]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function validatePlatform(string $platform): void
    {
        if (! in_array($platform, self::PLATFORMS, true)) {
            abort(404, "Unknown integration platform: {$platform}");
        }
    }

    /**
     * Return masked hints so the UI can show the dealer which credentials are saved
     * without exposing the full secret values.
     *
     * @param  array<string, string>|null  $creds
     * @return array<string, string>|null
     */
    private function maskCredentials(string $platform, ?array $creds): ?array
    {
        if (empty($creds)) {
            return null;
        }

        $mask = fn (string $value): string => substr($value, 0, 4) . str_repeat('•', max(0, strlen($value) - 4));

        return match ($platform) {
            'dealertrack' => [
                'dealer_id'     => $creds['dealer_id'] ?? '',
                'client_id'     => $mask($creds['client_id'] ?? ''),
                'client_secret' => $mask($creds['client_secret'] ?? ''),
            ],
            'routeone' => [
                'dealer_code' => $creds['dealer_code'] ?? '',
                'api_key'     => $mask($creds['api_key'] ?? ''),
                'partner_id'  => $creds['partner_id'] ?? '',
            ],
        };
    }
}
