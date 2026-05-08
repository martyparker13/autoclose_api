<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Decodes a 17-character VIN using the NHTSA vPIC public API and maps the
 * response to fields used by the Vehicle model.
 *
 * Results are cached for 30 days — VIN specs almost never change.
 *
 * @see https://vpic.nhtsa.dot.gov/api/
 */
class VinDecodeService
{
    private const BASE_URL = 'https://vpic.nhtsa.dot.gov/api/vehicles/DecodeVinValues';

    /** Cache TTL in seconds (30 days). */
    private const CACHE_TTL = 60 * 60 * 24 * 30;

    /**
     * Decode a VIN and return an array of vehicle fields extracted from the
     * NHTSA vPIC response.  Returns an empty array if the VIN is invalid,
     * the API is unreachable, or no useful data is returned.
     *
     * @return array<string, mixed>
     */
    public function decode(string $vin): array
    {
        $vin = strtoupper(trim($vin));

        if (strlen($vin) !== 17) {
            return [];
        }

        return Cache::remember("vin_decode:{$vin}", self::CACHE_TTL, fn () => $this->fetchFromNhtsa($vin));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchFromNhtsa(string $vin): array
    {
        try {
            $response = Http::timeout(10)
                ->get(self::BASE_URL . "/{$vin}", ['format' => 'json']);

            if (! $response->successful()) {
                Log::warning('VIN decode HTTP error', [
                    'vin'    => $vin,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $data = $response->json('Results.0');

            if (! is_array($data)) {
                return [];
            }

            return $this->mapNhtsaFields($data);
        } catch (\Throwable $e) {
            Log::warning('VIN decode failed', ['vin' => $vin, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Map NHTSA field names to our vehicle schema.
     * Only non-empty values are included.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapNhtsaFields(array $data): array
    {
        /** Returns the trimmed string value for a NHTSA key, or null if blank. */
        $val = function (string $key) use ($data): ?string {
            return (isset($data[$key]) && trim((string) $data[$key]) !== '')
                ? trim((string) $data[$key])
                : null;
        };

        $mapped = [];

        if ($v = $val('Make'))            $mapped['make']         = $v;
        if ($v = $val('Model'))           $mapped['model']        = $v;
        if ($v = $val('ModelYear'))       $mapped['year']         = (int) $v;
        if ($v = $val('Trim'))            $mapped['trim']         = $v;
        if ($v = $val('BodyClass'))       $mapped['body_style']   = $v;
        if ($v = $val('DriveType'))       $mapped['drivetrain']   = $v;
        if ($v = $val('FuelTypePrimary')) $mapped['fuel_type']    = $v;
        if ($v = $val('TransmissionStyle')) $mapped['transmission'] = $v;
        if ($v = $val('Doors'))           $mapped['doors']        = (int) $v;
        if ($v = $val('EngineCylinders')) $mapped['cylinders']    = (int) $v;

        // Build a human-readable engine string, e.g. "2.0L Inline 4-Cylinder DOHC"
        $displacement = $val('DisplacementL');
        $config       = $val('EngineConfiguration');
        $engineModel  = $val('EngineModel');

        $engineParts = array_filter([
            $displacement ? number_format((float) $displacement, 1) . 'L' : null,
            $config,
            $engineModel,
        ]);

        if (! empty($engineParts)) {
            $mapped['engine'] = implode(' ', $engineParts);
        }

        return $mapped;
    }
}
