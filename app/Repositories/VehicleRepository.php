<?php

namespace App\Repositories;

use App\Models\Vehicle;
use Illuminate\Contracts\Pagination\CursorPaginator;

class VehicleRepository implements VehicleRepositoryInterface
{
    /**
     * Paginate vehicles for a dealer with optional filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateForDealer(int $dealerId, array $filters = []): CursorPaginator
    {
        $query = Vehicle::with(['media' => fn ($q) => $q->where('is_primary', true)])
            ->forDealer($dealerId)
            ->withoutTrashed();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            // Default: exclude sold unless explicitly requested
            if (empty($filters['include_sold'])) {
                $query->where('status', '!=', 'sold');
            }
        }

        if (! empty($filters['condition'])) {
            $query->where('condition', $filters['condition']);
        }

        if (! empty($filters['make'])) {
            $query->where('make', $filters['make']);
        }

        if (! empty($filters['model'])) {
            $query->where('model', $filters['model']);
        }

        if (! empty($filters['year_min'])) {
            $query->where('year', '>=', $filters['year_min']);
        }

        if (! empty($filters['year_max'])) {
            $query->where('year', '<=', $filters['year_max']);
        }

        if (! empty($filters['price_min'])) {
            $query->where('price', '>=', (int) $filters['price_min'] * 100);
        }

        if (! empty($filters['price_max'])) {
            $query->where('price', '<=', (int) $filters['price_max'] * 100);
        }

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->where('make', 'like', "%{$term}%")
                  ->orWhere('model', 'like', "%{$term}%")
                  ->orWhere('trim', 'like', "%{$term}%")
                  ->orWhere('vin', 'like', "%{$term}%")
                  ->orWhere('stock_number', 'like', "%{$term}%");
            });
        }

        $sort = $filters['sort'] ?? 'created_at';
        $dir  = $filters['dir'] ?? 'desc';

        $allowedSorts = ['price', 'year', 'mileage', 'created_at'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'created_at';
        }

        $query->orderBy($sort, $dir === 'asc' ? 'asc' : 'desc');

        return $query->cursorPaginate(20);
    }

    /**
     * Find a single vehicle scoped to a dealer.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findForDealer(int $id, int $dealerId): Vehicle
    {
        return Vehicle::with(['media', 'features'])
            ->forDealer($dealerId)
            ->findOrFail($id);
    }

    /**
     * Create and return a new vehicle.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Vehicle
    {
        return Vehicle::create($data);
    }

    /**
     * Update a vehicle and return the refreshed model.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Vehicle $vehicle, array $data): Vehicle
    {
        $vehicle->update($data);

        return $vehicle->fresh(['media', 'features']);
    }

    /**
     * Soft-delete a vehicle.
     */
    public function delete(Vehicle $vehicle): void
    {
        $vehicle->delete();
    }
}
