<?php

namespace App\Http\Controllers\Api\V1;

use App\Jobs\ArchiveMissingDealerVehiclesJob;
use App\Jobs\SyncDealerVehiclesChunkJob;
use App\Http\Requests\Vehicle\ImportVehiclesRequest;
use App\Http\Requests\Vehicle\StoreVehicleRequest;
use App\Http\Requests\Vehicle\UpdateVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use App\Repositories\VehicleRepositoryInterface;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleController extends BaseController
{
    private const SYNC_CHUNK_SIZE = 250;

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

        $queueMode = $request->boolean('queue');
        if ($queueMode) {
            $jobs = [];

            foreach (array_chunk($payload, self::SYNC_CHUNK_SIZE) as $chunk) {
                $jobs[] = new SyncDealerVehiclesChunkJob($dealer->id, $chunk);
            }

            if ($request->boolean('archive_missing')) {
                $incomingVins = array_values(array_unique(array_filter(array_map(
                    fn ($row) => is_array($row) && isset($row['vin']) ? strtoupper(trim((string) $row['vin'])) : null,
                    $payload,
                ))));

                $incomingStocks = array_values(array_unique(array_filter(array_map(
                    fn ($row) => is_array($row) && isset($row['stock_number']) ? trim((string) $row['stock_number']) : null,
                    $payload,
                ))));

                $jobs[] = new ArchiveMissingDealerVehiclesJob($dealer->id, $incomingVins, $incomingStocks);
            }

            Bus::chain($jobs)->dispatch();

            return response()->json([
                'data' => [
                    'queued' => true,
                    'job_count' => count($jobs),
                    'chunk_size' => self::SYNC_CHUNK_SIZE,
                    'records_received' => count($payload),
                ],
            ], 202);
        }

        $syncResult = $this->inventory->syncFromPayload($dealer, $payload);
        $archived = 0;
        if ($request->boolean('archive_missing')) {
            $archived = $this->inventory->archiveMissingFromSync(
                $dealer,
                $syncResult['incoming_vins'],
                $syncResult['incoming_stocks'],
            );
        }

        return response()->json([
            'data' => [
                'created' => $syncResult['created'],
                'updated' => $syncResult['updated'],
                'skipped' => $syncResult['skipped'],
                'archived' => $archived,
            ],
            'errors' => $syncResult['errors'],
        ]);
    }
}
