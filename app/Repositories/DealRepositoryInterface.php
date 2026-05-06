<?php

namespace App\Repositories;

use App\Models\Deal;
use Illuminate\Contracts\Pagination\CursorPaginator;

interface DealRepositoryInterface
{
    /**
     * Paginate all deals for a dealer with optional filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateForDealer(int $dealerId, array $filters = []): CursorPaginator;

    /**
     * Paginate deals owned by a buyer with optional filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateForBuyer(int $buyerId, array $filters = []): CursorPaginator;

    /**
     * Find a deal scoped to a dealer (for dealer staff/admin).
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findForDealer(int $id, int $dealerId): Deal;

    /**
     * Find a deal owned by a buyer.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findForBuyer(int $id, int $buyerId): Deal;

    /**
     * Create a new deal.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Deal;

    /**
     * Update a deal and return the refreshed model.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Deal $deal, array $data): Deal;

    /**
     * Soft-delete a deal.
     */
    public function delete(Deal $deal): void;
}
