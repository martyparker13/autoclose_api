<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/vehicles/sync
 *
 * Bulk-upserts vehicle inventory from a dealer's DMS or website platform.
 * Authenticated via X-API-Key (ApiKeyMiddleware binds 'current_dealer').
 *
 * Request body: JSON array of vehicle objects.
 * Required per item: vin OR stock_number.
 *
 * Optional archival: if ?archive_missing=1 is passed, vehicles belonging to
 * this dealer that are NOT in the payload will be marked status=hold.
 */
class VehicleSyncController extends BaseController
{
    private const ALLOWED_FIELDS = [
        'stock_number', 'year', 'make', 'model', 'trim', 'body_style',
        'exterior_color', 'interior_color', 'mileage', 'condition',
        'price', 'msrp', 'internet_price', 'transmission', 'engine',
        'drivetrain', 'fuel_type', 'doors', 'cylinders', 'status',
        'description', 'carfax_url',
    ];

    private const VALID_CONDITIONS = ['new', 'used', 'certified'];
    private const VALID_STATUSES   = ['available', 'pending', 'sold', 'hold'];

    public function __invoke(Request $request): JsonResponse
    {
        $dealer = app('current_dealer');

        $payload = $request->json()->all();

        if (! is_array($payload) || empty($payload)) {
            return response()->json(['message' => 'Request body must be a non-empty JSON array.'], 422);
        }

        if (count($payload) > 5000) {
            return response()->json(['message' => 'Maximum 5000 vehicles per sync request.'], 422);
        }

        $created  = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = [];
        $seenVins = [];

        foreach ($payload as $index => $item) {
            if (! is_array($item)) {
                $errors[] = "Item {$index}: not an object.";
                $skipped++;
                continue;
            }

            $vin         = isset($item['vin']) ? strtoupper(trim((string) $item['vin'])) : null;
            $stockNumber = isset($item['stock_number']) ? trim((string) $item['stock_number']) : null;

            if (! $vin && ! $stockNumber) {
                $errors[] = "Item {$index}: vin or stock_number is required.";
                $skipped++;
                continue;
            }

            if ($vin && strlen($vin) !== 17) {
                $errors[] = "Item {$index}: VIN must be exactly 17 characters (got \"{$vin}\").";
                $skipped++;
                continue;
            }

            // Deduplicate within payload
            $dedupeKey = $vin ?? $stockNumber;
            if (isset($seenVins[$dedupeKey])) {
                $errors[] = "Item {$index}: duplicate VIN/stock \"{$dedupeKey}\" in payload.";
                $skipped++;
                continue;
            }
            $seenVins[$dedupeKey] = true;

            // Validate condition and status
            $condition = $item['condition'] ?? 'used';
            if (! in_array($condition, self::VALID_CONDITIONS, true)) {
                $condition = 'used';
            }
            $status = $item['status'] ?? 'available';
            if (! in_array($status, self::VALID_STATUSES, true)) {
                $status = 'available';
            }

            // Build attributes
            $attributes = ['dealer_id' => $dealer->id];
            if ($vin) $attributes['vin'] = $vin;
            if ($stockNumber) $attributes['stock_number'] = $stockNumber;

            $values = ['condition' => $condition, 'status' => $status];
            foreach (self::ALLOWED_FIELDS as $field) {
                if ($field === 'condition' || $field === 'status') continue;
                if (array_key_exists($field, $item)) {
                    // Price fields: accept dollars (float) and store as cents (int)
                    if (in_array($field, ['price', 'msrp', 'internet_price'], true)) {
                        $values[$field] = (int) round((float) $item[$field] * 100);
                    } else {
                        $values[$field] = $item[$field];
                    }
                }
            }

            // Upsert — match on (dealer_id + vin) or (dealer_id + stock_number)
            $matchColumn = $vin ? 'vin' : 'stock_number';
            $existing = Vehicle::where('dealer_id', $dealer->id)
                ->where($matchColumn, $attributes[$matchColumn])
                ->withTrashed()
                ->first();

            if ($existing) {
                $existing->restore(); // un-soft-delete if previously removed
                $existing->fill($values)->save();
                $updated++;
            } else {
                Vehicle::create(array_merge($attributes, $values));
                $created++;
            }
        }

        // Archive vehicles not in this payload
        if ($request->boolean('archive_missing')) {
            $incomingVins = array_filter(array_map(
                fn ($i) => isset($i['vin']) ? strtoupper(trim((string) $i['vin'])) : null,
                $payload,
            ));
            $incomingStocks = array_filter(array_map(
                fn ($i) => isset($i['stock_number']) ? trim((string) $i['stock_number']) : null,
                $payload,
            ));

            Vehicle::where('dealer_id', $dealer->id)
                ->where('status', 'available')
                ->where(function ($q) use ($incomingVins, $incomingStocks) {
                    if ($incomingVins) {
                        $q->whereNotIn('vin', $incomingVins);
                    }
                    if ($incomingStocks) {
                        $q->whereNotIn('stock_number', $incomingStocks);
                    }
                })
                ->update(['status' => 'hold']);
        }

        return response()->json([
            'data' => compact('created', 'updated', 'skipped'),
            'errors' => $errors,
        ]);
    }
}
