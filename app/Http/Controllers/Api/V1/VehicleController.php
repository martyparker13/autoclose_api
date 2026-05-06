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
}
