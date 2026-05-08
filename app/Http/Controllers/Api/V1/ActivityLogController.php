<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityLogController extends BaseController
{
    /**
     * Paginated audit log for the current dealer (dealer admin / staff).
     * GET /dealer/audit-log
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $dealer = app('current_dealer');

        $query = ActivityLog::with(['user', 'dealer'])
            ->where('dealer_id', $dealer->id)
            ->orderByDesc('created_at');

        $this->applyFilters($query, $request);

        return ActivityLogResource::collection($query->paginate(50));
    }

    /**
     * Paginated audit log across all dealers (super admin).
     * GET /admin/audit-log
     */
    public function adminIndex(Request $request): AnonymousResourceCollection
    {
        $query = ActivityLog::with(['user', 'dealer'])
            ->orderByDesc('created_at');

        if ($request->filled('dealer_id')) {
            $query->where('dealer_id', $request->integer('dealer_id'));
        }

        $this->applyFilters($query, $request);

        return ActivityLogResource::collection($query->paginate(50));
    }

    /** Apply common filter parameters to the query. */
    private function applyFilters(\Illuminate\Database\Eloquent\Builder $query, Request $request): void
    {
        if ($request->filled('event')) {
            $query->where('event', $request->string('event'));
        }

        if ($request->filled('model_type')) {
            $query->where('model_type', 'like', '%' . $request->string('model_type'));
        }

        if ($request->filled('model_id')) {
            $query->where('model_id', $request->integer('model_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->string('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->string('to'));
        }
    }
}
