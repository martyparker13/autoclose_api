<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\DealerResource;
use App\Models\Dealer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealerController extends BaseController
{
    /**
     * List all dealers (paginated, with optional search).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Dealer::withTrashed()
            ->when($request->filled('q'), fn ($q) => $q->where(function ($inner) use ($request) {
                $term = '%' . $request->query('q') . '%';
                $inner->where('name', 'like', $term)
                    ->orWhere('subdomain', 'like', $term)
                    ->orWhere('email', 'like', $term);
            }))
            ->orderBy('created_at', 'desc')
            ->paginate(25);

        return response()->json([
            'data' => DealerResource::collection($query),
            'meta' => [
                'total'        => $query->total(),
                'per_page'     => $query->perPage(),
                'current_page' => $query->currentPage(),
                'last_page'    => $query->lastPage(),
            ],
        ]);
    }

    /**
     * Create a new dealer.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'              => ['required', 'string', 'max:255'],
            'subdomain'         => ['required', 'string', 'max:63', 'unique:dealers,subdomain', 'regex:/^[a-z0-9-]+$/'],
            'email'             => ['required', 'email', 'max:255'],
            'phone'             => ['nullable', 'string', 'max:20'],
            'address'           => ['nullable', 'string', 'max:255'],
            'city'              => ['nullable', 'string', 'max:100'],
            'state'             => ['nullable', 'string', 'max:50'],
            'zip'               => ['nullable', 'string', 'max:20'],
            'subscription_plan' => ['nullable', 'string', 'in:starter,professional,enterprise'],
            'is_active'         => ['boolean'],
        ]);

        $data['slug']   = $data['subdomain'];
        $dealer = Dealer::create($data);

        return $this->resourceResponse(new DealerResource($dealer), 201);
    }

    /**
     * Show a single dealer.
     */
    public function show(int $dealer): JsonResponse
    {
        $model = Dealer::withTrashed()->findOrFail($dealer);

        return $this->resourceResponse(new DealerResource($model));
    }

    /**
     * Update dealer details.
     */
    public function update(Request $request, int $dealer): JsonResponse
    {
        $model = Dealer::withTrashed()->findOrFail($dealer);

        $data = $request->validate([
            'name'              => ['sometimes', 'string', 'max:255'],
            'email'             => ['sometimes', 'email', 'max:255'],
            'phone'             => ['nullable', 'string', 'max:20'],
            'address'           => ['nullable', 'string', 'max:255'],
            'city'              => ['nullable', 'string', 'max:100'],
            'state'             => ['nullable', 'string', 'max:50'],
            'zip'               => ['nullable', 'string', 'max:20'],
            'logo_url'          => ['nullable', 'url'],
            'primary_color'     => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'subscription_plan' => ['nullable', 'string', 'in:starter,professional,enterprise'],
            'subscription_status' => ['nullable', 'string', 'in:active,past_due,canceled,trialing'],
            'is_active'         => ['boolean'],
            'feature_flags'     => ['nullable', 'array'],
        ]);

        $model->update($data);

        return $this->resourceResponse(new DealerResource($model->fresh()));
    }

    /**
     * Soft-delete a dealer.
     */
    public function destroy(int $dealer): JsonResponse
    {
        $model = Dealer::findOrFail($dealer);
        $model->delete();

        return $this->noContent();
    }

    /**
     * Restore a soft-deleted dealer.
     */
    public function restore(int $dealer): JsonResponse
    {
        $model = Dealer::withTrashed()->findOrFail($dealer);
        $model->restore();

        return $this->resourceResponse(new DealerResource($model));
    }
}
