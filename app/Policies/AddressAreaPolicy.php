<?php

declare(strict_types=1);

namespace App\Policies;

use AIArmada\Addressing\Models\AddressArea;
use App\Models\User;

class AddressAreaPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, AddressArea $addressArea): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function update(User $user, AddressArea $addressArea): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function delete(User $user, AddressArea $addressArea): bool
    {
        return $user->hasRole('super_admin');
    }

    public function restore(User $user, AddressArea $addressArea): bool
    {
        return $user->hasRole('super_admin');
    }

    public function forceDelete(User $user, AddressArea $addressArea): bool
    {
        return $user->hasRole('super_admin');
    }
}
