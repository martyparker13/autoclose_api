<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Vehicle\StoreVehicleFeatureRequest;
use App\Http\Resources\VehicleFeatureResource;
use App\Models\VehicleFeature;
use App\Repositories\VehicleRepositoryInterface;
use Illuminate\Http\JsonResponse;

class VehicleFeatureController extends BaseController
{
    public function __construct(
        private readonly VehicleRepositoryInterface $repo,
    ) {}

    /**
     * Sync (replace) all features for a vehicle.
     *
     * Accepts an array of features; replaces the entire feature set atomically.
     */
    public function store(StoreVehicleFeatureRequest $request, int $vehicle): JsonResponse
    {
        $dealer  = app('current_dealer');
        $model   = $this->repo->findForDealer($vehicle, $dealer->id);

        $model->features()->delete();

        $features = $model->features()->createMany($request->validated('features'));

        return response()->json([
            'data' => VehicleFeatureResource::collection($features),
        ], 201);
    }

    /**
     * Delete a single feature from a vehicle.
     */
    public function destroy(int $vehicle, int $feature): JsonResponse
    {
        $dealer = app('current_dealer');
        $model  = $this->repo->findForDealer($vehicle, $dealer->id);

        VehicleFeature::where('id', $feature)
            ->where('vehicle_id', $model->id)
            ->firstOrFail()
            ->delete();

        return $this->noContent();
    }
}
