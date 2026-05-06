<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;

class VehiclePolicy
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
     * Buyers and dealer staff/admin can view vehicles belonging to their dealer.
     */
    public function view(User $user, Vehicle $vehicle): bool
    {
        return $user->dealer_id === $vehicle->dealer_id;
    }

    /**
     * Only dealer admin and dealer staff can create vehicles.
     */
    public function create(User $user): bool
    {
        return $user->belongsToDealer();
    }

    /**
     * Dealer admin and staff can update vehicles in their dealership.
     */
    public function update(User $user, Vehicle $vehicle): bool
    {
        return $user->belongsToDealer() && $user->dealer_id === $vehicle->dealer_id;
    }

    /**
     * Only dealer admin can delete vehicles.
     */
    public function delete(User $user, Vehicle $vehicle): bool
    {
        return $user->isDealerAdmin() && $user->dealer_id === $vehicle->dealer_id;
    }
}
