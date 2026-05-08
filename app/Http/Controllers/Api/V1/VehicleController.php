<?php

namespace App\Http\Controllers\Api\V1;

use App\Jobs\ArchiveMissingDealerVehiclesJob;
use App\Jobs\SyncDealerVehiclesChunkJob;
use App\Http\Requests\Vehicle\ImportVehiclesRequest;
use App\Http\Requests\Vehicle\StoreVehicleRequest;
use App\Http\Requests\Vehicle\UpdateVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\ActivityLog;
use App\Models\DealerSyncRun;
use App\Models\Vehicle;
use App\Repositories\VehicleRepositoryInterface;
use App\Services\InventoryService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

        ActivityLog::record('vehicle.created', $vehicle, [], $vehicle->only(['vin', 'year', 'make', 'model', 'price']));

        return $this->resourceResponse(new VehicleResource($vehicle), 201);
    }

    /**
     * Update an existing vehicle listing.
     */
    public function update(UpdateVehicleRequest $request, int $vehicle): JsonResponse
    {
        $dealer  = app('current_dealer');
        $model   = $this->repo->findForDealer($vehicle, $dealer->id);
        $old     = $model->only(['vin', 'year', 'make', 'model', 'price', 'status']);
        $updated = $this->inventory->update($model, $dealer, $request->validated());

        ActivityLog::record('vehicle.updated', $updated, $old, $updated->only(array_keys($old)));

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

        ActivityLog::record('vehicle.deleted', $model, $model->only(['vin', 'year', 'make', 'model']), []);

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
            $syncRun = DealerSyncRun::create([
                'public_id' => (string) Str::uuid(),
                'dealer_id' => $dealer->id,
                'status' => 'queued',
                'archive_missing' => $request->boolean('archive_missing'),
                'total_records' => count($payload),
                'chunk_size' => self::SYNC_CHUNK_SIZE,
            ]);

            $jobs = [];

            foreach (array_chunk($payload, self::SYNC_CHUNK_SIZE) as $chunk) {
                $jobs[] = new SyncDealerVehiclesChunkJob($dealer->id, $syncRun->id, $chunk);
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

                $jobs[] = new ArchiveMissingDealerVehiclesJob($dealer->id, $syncRun->id, $incomingVins, $incomingStocks);
            }

            $syncRun->update(['total_jobs' => count($jobs)]);

            Bus::chain($jobs)->dispatch();

            return response()->json([
                'data' => [
                    'queued' => true,
                    'sync_run_id' => $syncRun->public_id,
                    'status' => $syncRun->status,
                    'status_path' => "/api/v1/vehicles/sync-runs/{$syncRun->public_id}",
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

    /**
     * Return status/progress for an asynchronous DMS sync run.
     */
    public function syncStatus(string $runId): JsonResponse
    {
        $dealer = app('current_dealer');

        $run = DealerSyncRun::where('public_id', $runId)
            ->where('dealer_id', $dealer->id)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'sync_run_id' => $run->public_id,
                'status' => $run->status,
                'archive_missing' => $run->archive_missing,
                'total_records' => $run->total_records,
                'chunk_size' => $run->chunk_size,
                'total_jobs' => $run->total_jobs,
                'processed_jobs' => $run->processed_jobs,
                'progress_percent' => $run->total_jobs > 0
                    ? (int) floor(($run->processed_jobs / $run->total_jobs) * 100)
                    : 0,
                'created' => $run->created,
                'updated' => $run->updated,
                'skipped' => $run->skipped,
                'archived' => $run->archived,
                'error_count' => $run->error_count,
                'errors' => $run->errors ?? [],
                'started_at' => $run->started_at,
                'completed_at' => $run->completed_at,
                'created_at' => $run->created_at,
                'updated_at' => $run->updated_at,
            ],
        ]);
    }

    /**
     * Return recent asynchronous DMS sync runs for the current dealer.
     */
    public function syncRuns(Request $request): JsonResponse
    {
        $dealer = app('current_dealer');
        $limit = max(1, min((int) $request->query('limit', 10), 50));

        $runs = DealerSyncRun::where('dealer_id', $dealer->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $runs->map(fn (DealerSyncRun $run) => [
                'sync_run_id' => $run->public_id,
                'status' => $run->status,
                'archive_missing' => $run->archive_missing,
                'total_records' => $run->total_records,
                'chunk_size' => $run->chunk_size,
                'total_jobs' => $run->total_jobs,
                'processed_jobs' => $run->processed_jobs,
                'progress_percent' => $run->total_jobs > 0
                    ? (int) floor(($run->processed_jobs / $run->total_jobs) * 100)
                    : 0,
                'created' => $run->created,
                'updated' => $run->updated,
                'skipped' => $run->skipped,
                'archived' => $run->archived,
                'error_count' => $run->error_count,
                'started_at' => $run->started_at,
                'completed_at' => $run->completed_at,
                'created_at' => $run->created_at,
                'updated_at' => $run->updated_at,
            ]),
        ]);
    }

    /**
     * Trigger on-demand VIN decode enrichment for a single vehicle.
     *
     * Only blank / null spec fields are filled in — the dealer's existing data
     * is never overwritten.  A 422 is returned when the vehicle has no VIN.
     */
    public function decodeVin(Request $request, Vehicle $vehicle): JsonResponse
    {
        $dealer = app('current_dealer');

        if ($vehicle->dealer_id !== $dealer->id) {
            abort(403, 'Vehicle does not belong to this dealer.');
        }

        if (! $vehicle->vin) {
            return response()->json([
                'message' => 'Vehicle has no VIN and cannot be decoded.',
            ], 422);
        }

        $applied = $this->inventory->enrichFromVin($vehicle);

        return response()->json([
            'data' => new VehicleResource($vehicle->fresh()),
            'meta' => [
                'enriched_fields' => $applied,
                'vin_decoded_at'  => $vehicle->vin_decoded_at,
            ],
        ]);
    }
}
