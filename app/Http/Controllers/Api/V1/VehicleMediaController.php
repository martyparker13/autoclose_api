<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\VehicleMediaResource;
use App\Models\VehicleMedia;
use App\Repositories\VehicleRepositoryInterface;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleMediaController extends BaseController
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly VehicleRepositoryInterface $repo,
    ) {}

    /**
     * Upload a media file to a vehicle.
     */
    public function store(Request $request, int $vehicle): JsonResponse
    {
        $request->validate([
            'file'  => ['required', 'file', 'mimes:jpeg,jpg,png,webp,mp4,mov', 'max:102400'],
            'type'  => ['nullable', 'in:photo,video'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $dealer  = app('current_dealer');
        $model   = $this->repo->findForDealer($vehicle, $dealer->id);
        $media   = $this->inventory->addMedia(
            $model,
            $request->file('file'),
            $request->input('type', 'photo'),
            $request->input('label'),
        );

        return $this->resourceResponse(new VehicleMediaResource($media), 201);
    }

    /**
     * Reorder media items for a vehicle.
     */
    public function reorder(Request $request, int $vehicle): JsonResponse
    {
        $request->validate([
            'order'   => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $dealer = app('current_dealer');
        $model  = $this->repo->findForDealer($vehicle, $dealer->id);
        $this->inventory->reorderMedia($model, $request->input('order'));

        return $this->noContent();
    }

    /**
     * Delete a media item from a vehicle.
     */
    public function destroy(int $vehicle, int $media): JsonResponse
    {
        $dealer     = app('current_dealer');
        $vehicleModel = $this->repo->findForDealer($vehicle, $dealer->id);
        $mediaModel = VehicleMedia::where('id', $media)
            ->where('vehicle_id', $vehicleModel->id)
            ->firstOrFail();

        $this->inventory->deleteMedia($mediaModel);

        return $this->noContent();
    }
}
