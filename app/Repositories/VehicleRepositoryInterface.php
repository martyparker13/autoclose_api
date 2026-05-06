<?php

namespace App\Repositories;

use App\Models\Vehicle;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Collection;

interface VehicleRepositoryInterface
{
    /**
     * Paginate vehicles for a dealer, applying optional filters.
     *
     * @param  int                  $dealerId
     * @param  array<string, mixed> $filters
     */
    public function paginateForDealer(int $dealerId, array $filters = []): CursorPaginator;

    /**
     * Paginate all available vehicles across all dealers (marketplace browse).
     *
     * @param  array<string, mixed> $filters
     */
    public function paginateAll(array $filters = []): CursorPaginator;

    /**
     * Find a vehicle by ID scoped to a dealer.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findForDealer(int $id, int $dealerId): Vehicle;

    /**
     * Create a new vehicle.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Vehicle;

    /**
     * Update an existing vehicle.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Vehicle $vehicle, array $data): Vehicle;

    /**
     * Soft-delete a vehicle.
     */
    public function delete(Vehicle $vehicle): void;
}
