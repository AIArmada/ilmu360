<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DonationChannel;
use App\Models\User;

class DonationChannelPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, DonationChannel $donationChannel): bool
    {
        if ($donationChannel->status === 'verified') {
            return true;
        }

        return $user instanceof User
            && $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function update(User $user, DonationChannel $donationChannel): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function delete(User $user, DonationChannel $donationChannel): bool
    {
        return $user->hasRole('super_admin');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function restore(User $user, DonationChannel $donationChannel): bool
    {
        return $user->hasRole('super_admin');
    }

    public function forceDelete(User $user, DonationChannel $donationChannel): bool
    {
        return $user->hasRole('super_admin');
    }
}
