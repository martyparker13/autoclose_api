<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\DealerGroupResource;
use App\Http\Resources\DealerResource;
use App\Models\Dealer;
use App\Models\DealerGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DealerGroupController extends BaseController
{
    /**
     * GET /admin/groups
     * List all dealer groups (super admin).
     */
    public function index(Request $request): JsonResponse
    {
        $groups = DealerGroup::withTrashed()
            ->withCount('dealers')
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%' . $request->query('q') . '%'))
            ->orderBy('created_at', 'desc')
            ->paginate(25);

        return response()->json([
            'data' => DealerGroupResource::collection($groups),
            'meta' => [
                'total'        => $groups->total(),
                'per_page'     => $groups->perPage(),
                'current_page' => $groups->currentPage(),
                'last_page'    => $groups->lastPage(),
            ],
        ]);
    }

    /**
     * POST /admin/groups
     * Create a new dealer group.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'slug'          => ['nullable', 'string', 'max:63', 'unique:dealer_groups,slug', 'regex:/^[a-z0-9-]+$/'],
            'logo_url'      => ['nullable', 'url', 'max:500'],
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'is_active'     => ['boolean'],
        ]);

        $data['slug'] ??= Str::slug($data['name']);

        $group = DealerGroup::create($data);

        return $this->resourceResponse(new DealerGroupResource($group), 201);
    }

    /**
     * GET /admin/groups/{group}
     * Show a single group with its dealers.
     */
    public function show(int $group): JsonResponse
    {
        $model = DealerGroup::withTrashed()->with('dealers')->withCount('dealers')->findOrFail($group);

        return response()->json([
            'data' => array_merge(
                (new DealerGroupResource($model))->resolve(),
                ['dealers' => DealerResource::collection($model->dealers)],
            ),
        ]);
    }

    /**
     * PATCH /admin/groups/{group}
     * Update a dealer group.
     */
    public function update(Request $request, int $group): JsonResponse
    {
        $model = DealerGroup::withTrashed()->findOrFail($group);

        $data = $request->validate([
            'name'          => ['sometimes', 'string', 'max:255'],
            'logo_url'      => ['nullable', 'url', 'max:500'],
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'is_active'     => ['boolean'],
        ]);

        $model->update($data);

        return $this->resourceResponse(new DealerGroupResource($model->fresh()));
    }

    /**
     * DELETE /admin/groups/{group}
     * Soft-delete a dealer group.
     */
    public function destroy(int $group): JsonResponse
    {
        DealerGroup::findOrFail($group)->delete();

        return $this->noContent();
    }

    /**
     * POST /admin/groups/{group}/restore
     * Restore a soft-deleted group.
     */
    public function restore(int $group): JsonResponse
    {
        $model = DealerGroup::onlyTrashed()->findOrFail($group);
        $model->restore();

        return $this->resourceResponse(new DealerGroupResource($model));
    }

    /**
     * POST /admin/groups/{group}/dealers/{dealer}
     * Assign a dealer to a group.
     */
    public function addDealer(int $group, int $dealer): JsonResponse
    {
        DealerGroup::findOrFail($group);
        $dealerModel = Dealer::findOrFail($dealer);
        $dealerModel->update(['dealer_group_id' => $group]);

        return $this->resourceResponse(new DealerResource($dealerModel->fresh()));
    }

    /**
     * DELETE /admin/groups/{group}/dealers/{dealer}
     * Remove a dealer from a group.
     */
    public function removeDealer(int $group, int $dealer): JsonResponse
    {
        $dealerModel = Dealer::where('dealer_group_id', $group)->findOrFail($dealer);
        $dealerModel->update(['dealer_group_id' => null]);

        return $this->noContent();
    }
}
