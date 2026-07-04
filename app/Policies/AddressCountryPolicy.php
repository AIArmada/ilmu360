<?php

declare(strict_types=1);

namespace App\Policies;

use AIArmada\Addressing\Models\AddressCountry;
use App\Models\User;

class AddressCountryPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, AddressCountry $addressCountry): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function update(User $user, AddressCountry $addressCountry): bool
    {
        return $user->hasRole('super_admin');
    }

    public function delete(User $user, AddressCountry $addressCountry): bool
    {
        return $user->hasRole('super_admin');
    }

    public function restore(User $user, AddressCountry $addressCountry): bool
    {
        return $user->hasRole('super_admin');
    }

    public function forceDelete(User $user, AddressCountry $addressCountry): bool
    {
        return $user->hasRole('super_admin');
    }
}
