<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Vehicle\ImportVehiclesRequest;
use App\Http\Requests\Vehicle\StoreVehicleRequest;
use App\Http\Requests\Vehicle\UpdateVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use App\Repositories\VehicleRepositoryInterface;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleController extends BaseController
{
    private const SYNC_ALLOWED_FIELDS = [
        'stock_number', 'year', 'make', 'model', 'trim', 'body_style',
        'exterior_color', 'interior_color', 'mileage', 'condition',
        'price', 'msrp', 'internet_price', 'transmission', 'engine',
        'drivetrain', 'fuel_type', 'doors', 'cylinders', 'status',
        'description', 'carfax_url',
    ];

    private const SYNC_VALID_CONDITIONS = ['new', 'used', 'certified'];
    private const SYNC_VALID_STATUSES = ['available', 'pending', 'sold', 'hold'];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly VehicleRepositoryInterface $repo,
    ) {}

    /**
     * List vehicles.
     *
     * - With dealer context (tenant middleware present): returns that dealer's vehicles.
     * - Without dealer context (public marketplace browse): returns all available vehicles.
     */
    public function index(Request $request): JsonResponse
    {
        $dealer = app()->bound('current_dealer') ? app('current_dealer') : null;

        if ($dealer) {
            if ($request->filled('q')) {
                $vehicles = $this->inventory->search($dealer, (string) $request->query('q'));

                return response()->json([
                    'data' => VehicleResource::collection($vehicles),
                    'meta' => ['next_cursor' => null, 'per_page' => count($vehicles)],
                ]);
            }

            $vehicles = $this->inventory->list($dealer, $request->query());
        } else {
            // Marketplace browse — no tenant context, show all available vehicles
            $vehicles = $this->repo->paginateAll($request->query());
        }

        return response()->json([
            'data' => VehicleResource::collection($vehicles),
            'meta' => [
                'next_cursor' => method_exists($vehicles, 'nextCursor') ? $vehicles->nextCursor()?->encode() : null,
                'per_page'    => 20,
            ],
        ]);
    }

    /**
     * Show a single vehicle with media and features.
     *
     * - With dealer context: scoped to that dealer.
     * - Without dealer context: any vehicle by ID.
     */
    public function show(int $vehicle): JsonResponse
    {
        $dealer = app()->bound('current_dealer') ? app('current_dealer') : null;

        if ($dealer) {
            $model = $this->repo->findForDealer($vehicle, $dealer->id);
        } else {
            $model = Vehicle::with(['media', 'features'])->findOrFail($vehicle);
        }

        return $this->resourceResponse(new VehicleResource($model));
    }

    /**
     * Create a new vehicle listing.
     */
    public function store(StoreVehicleRequest $request): JsonResponse
    {
        $dealer  = app('current_dealer');
        $vehicle = $this->inventory->create($dealer, $request->validated());

        return $this->resourceResponse(new VehicleResource($vehicle), 201);
    }

    /**
     * Update an existing vehicle listing.
     */
    public function update(UpdateVehicleRequest $request, int $vehicle): JsonResponse
    {
        $dealer  = app('current_dealer');
        $model   = $this->repo->findForDealer($vehicle, $dealer->id);
        $updated = $this->inventory->update($model, $dealer, $request->validated());

        return $this->resourceResponse(new VehicleResource($updated));
    }

    /**
     * Soft-delete a vehicle listing.
     */
    public function destroy(int $vehicle): JsonResponse
    {
        $dealer = app('current_dealer');
        $model  = $this->repo->findForDealer($vehicle, $dealer->id);
        $this->inventory->delete($model, $dealer);

        return $this->noContent();
    }

    /**
     * Bulk-import vehicles from an uploaded CSV file.
     * Upserts by VIN. Returns a summary of created/updated/skipped rows.
     */
    public function import(ImportVehiclesRequest $request): JsonResponse
    {
        $dealer = app('current_dealer');
        $result = $this->inventory->importFromCsv($dealer, $request->file('file'));

        return response()->json(['data' => $result], 200);
    }

    /**
     * Bulk-upsert vehicles from a JSON payload.
     *
     * Authenticated via API key middleware that binds current_dealer.
     */
    public function sync(Request $request): JsonResponse
    {
        $dealer = app('current_dealer');
        $payload = $request->json()->all();

        if (! is_array($payload) || empty($payload)) {
            return response()->json(['message' => 'Request body must be a non-empty JSON array.'], 422);
        }

        if (count($payload) > 5000) {
            return response()->json(['message' => 'Maximum 5000 vehicles per sync request.'], 422);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $seen = [];

        foreach ($payload as $index => $item) {
            if (! is_array($item)) {
                $errors[] = "Item {$index}: not an object.";
                $skipped++;
                continue;
            }

            $vin = isset($item['vin']) ? strtoupper(trim((string) $item['vin'])) : null;
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

            $dedupeKey = $vin ?? $stockNumber;
            if (isset($seen[$dedupeKey])) {
                $errors[] = "Item {$index}: duplicate VIN/stock \"{$dedupeKey}\" in payload.";
                $skipped++;
                continue;
            }
            $seen[$dedupeKey] = true;

            $condition = $item['condition'] ?? 'used';
            if (! in_array($condition, self::SYNC_VALID_CONDITIONS, true)) {
                $condition = 'used';
            }

            $status = $item['status'] ?? 'available';
            if (! in_array($status, self::SYNC_VALID_STATUSES, true)) {
                $status = 'available';
            }

            $attributes = ['dealer_id' => $dealer->id];
            if ($vin) {
                $attributes['vin'] = $vin;
            }
            if ($stockNumber) {
                $attributes['stock_number'] = $stockNumber;
            }

            $values = ['condition' => $condition, 'status' => $status];
            foreach (self::SYNC_ALLOWED_FIELDS as $field) {
                if ($field === 'condition' || $field === 'status') {
                    continue;
                }
                if (! array_key_exists($field, $item)) {
                    continue;
                }

                if (in_array($field, ['price', 'msrp', 'internet_price'], true)) {
                    $values[$field] = (int) round((float) $item[$field] * 100);
                } else {
                    $values[$field] = $item[$field];
                }
            }

            $matchColumn = $vin ? 'vin' : 'stock_number';
            $existing = Vehicle::where('dealer_id', $dealer->id)
                ->where($matchColumn, $attributes[$matchColumn])
                ->withTrashed()
                ->first();

            if ($existing) {
                $existing->restore();
                $existing->fill($values)->save();
                $updated++;
            } else {
                Vehicle::create(array_merge($attributes, $values));
                $created++;
            }
        }

        $archived = 0;
        if ($request->boolean('archive_missing')) {
            $incomingVins = array_values(array_filter(array_map(
                fn (array $row): ?string => isset($row['vin']) ? strtoupper(trim((string) $row['vin'])) : null,
                array_filter($payload, fn ($row) => is_array($row)),
            )));

            $incomingStocks = array_values(array_filter(array_map(
                fn (array $row): ?string => isset($row['stock_number']) ? trim((string) $row['stock_number']) : null,
                array_filter($payload, fn ($row) => is_array($row)),
            )));

            $archiveQuery = Vehicle::where('dealer_id', $dealer->id)
                ->where('status', 'available');

            if (! empty($incomingVins)) {
                $archiveQuery->whereNotIn('vin', $incomingVins);
            }

            if (! empty($incomingStocks)) {
                $archiveQuery->whereNotIn('stock_number', $incomingStocks);
            }

            $archived = $archiveQuery->update(['status' => 'hold']);
        }

        return response()->json([
            'data' => compact('created', 'updated', 'skipped', 'archived'),
            'errors' => $errors,
        ]);
    }
}
