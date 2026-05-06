<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
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
     * Dealer admin can view users within their dealership.
     * Users can view their own record.
     */
    public function view(User $user, User $target): bool
    {
        if ($user->id === $target->id) {
            return true;
        }

        return $user->isDealerAdmin() && $user->dealer_id === $target->dealer_id;
    }

    /**
     * Dealer admin can create staff users in their dealership.
     */
    public function create(User $user): bool
    {
        return $user->isDealerAdmin();
    }

    /**
     * Dealer admin can update users in their dealership.
     * Users can update their own profile.
     */
    public function update(User $user, User $target): bool
    {
        if ($user->id === $target->id) {
            return true;
        }

        return $user->isDealerAdmin() && $user->dealer_id === $target->dealer_id;
    }

    /**
     * Only dealer admin can deactivate/delete users in their dealership.
     * Cannot delete yourself.
     */
    public function delete(User $user, User $target): bool
    {
        return $user->isDealerAdmin()
            && $user->dealer_id === $target->dealer_id
            && $user->id !== $target->id;
    }
}
