<?php

namespace App\Repositories;

use App\Models\Deal;
use Illuminate\Contracts\Pagination\CursorPaginator;

class DealRepository implements DealRepositoryInterface
{
    /** Default eager-loads for a deal list item. */
    private const LIST_WITH = ['vehicle.media', 'buyer'];

    /** Default eager-loads for a deal detail view. */
    private const DETAIL_WITH = [
        'vehicle.media',
        'vehicle.features',
        'buyer',
        'salesperson',
        'dealFiProducts.fiProduct',
        'creditApplication',
        'tradeInAppraisal',
        'documents',
        'deliveryAppointment',
        'scenarios',
    ];

    /**
     * Paginate all deals for a dealer with optional filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateForDealer(int $dealerId, array $filters = []): CursorPaginator
    {
        $query = Deal::with(self::LIST_WITH)
            ->forDealer($dealerId)
            ->withoutTrashed();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['buyer_id'])) {
            $query->where('buyer_id', (int) $filters['buyer_id']);
        }

        $sort    = in_array($filters['sort'] ?? '', ['created_at', 'sale_price', 'status'], true)
            ? $filters['sort']
            : 'created_at';
        $dir     = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $dir)->cursorPaginate(20);
    }

    /**
     * Paginate deals owned by a buyer.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateForBuyer(int $buyerId, array $filters = []): CursorPaginator
    {
        $query = Deal::with(self::LIST_WITH)
            ->where('buyer_id', $buyerId)
            ->withoutTrashed();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('created_at', 'desc')->cursorPaginate(20);
    }

    /**
     * Find a deal scoped to a dealer.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findForDealer(int $id, int $dealerId): Deal
    {
        return Deal::with(self::DETAIL_WITH)
            ->forDealer($dealerId)
            ->findOrFail($id);
    }

    /**
     * Find a deal owned by a buyer.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findForBuyer(int $id, int $buyerId): Deal
    {
        return Deal::with(self::DETAIL_WITH)
            ->where('buyer_id', $buyerId)
            ->findOrFail($id);
    }

    /**
     * Create a new deal.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Deal
    {
        $deal = Deal::create($data);

        return $deal->load(self::DETAIL_WITH);
    }

    /**
     * Update a deal and return the refreshed model.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Deal $deal, array $data): Deal
    {
        $deal->update($data);

        return $deal->fresh(self::DETAIL_WITH);
    }

    /**
     * Soft-delete a deal.
     */
    public function delete(Deal $deal): void
    {
        $deal->delete();
    }
}
