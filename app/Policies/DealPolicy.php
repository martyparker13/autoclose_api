<?php

namespace App\Policies;

use App\Models\Deal;
use App\Models\User;

class DealPolicy
{
    /**
     * Super admins bypass all policy checks.
     */
    public function before(User $user): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    /**
     * Buyers can view their own deals; dealer staff/admin can view deals in their dealer.
     */
    public function view(User $user, Deal $deal): bool
    {
        if ($user->isBuyer()) {
            return $user->id === $deal->buyer_id;
        }

        return $user->dealer_id === $deal->dealer_id;
    }

    /**
     * Any authenticated buyer can start a deal (create).
     */
    public function create(User $user): bool
    {
        return $user->isBuyer() || $user->belongsToDealer();
    }

    /**
     * Buyers can update their own draft deals; dealer staff can update any deal in their dealer.
     */
    public function update(User $user, Deal $deal): bool
    {
        if ($user->isBuyer()) {
            return $user->id === $deal->buyer_id && $deal->status === 'draft';
        }

        return $user->dealer_id === $deal->dealer_id;
    }

    /**
     * Only dealer admin can cancel/delete deals.
     */
    public function delete(User $user, Deal $deal): bool
    {
        return $user->isDealerAdmin() && $user->dealer_id === $deal->dealer_id;
    }
}
