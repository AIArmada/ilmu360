<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DonationChannel;
use App\Shared\Auth\AccountActor;
use App\Shared\Directory\Enums\DonationChannelStatus;

class DonationChannelPolicy
{
    public function viewAny(?AccountActor $user): bool
    {
        return true;
    }

    public function view(?AccountActor $user, DonationChannel $donationChannel): bool
    {
        if ($donationChannel->status === DonationChannelStatus::Verified) {
            return true;
        }

        return $user instanceof AccountActor
            && $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function create(AccountActor $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function update(AccountActor $user, DonationChannel $donationChannel): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function delete(AccountActor $user, DonationChannel $donationChannel): bool
    {
        return $user->hasRole('super_admin');
    }

    public function deleteAny(AccountActor $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function restore(AccountActor $user, DonationChannel $donationChannel): bool
    {
        return $user->hasRole('super_admin');
    }

    public function forceDelete(AccountActor $user, DonationChannel $donationChannel): bool
    {
        return $user->hasRole('super_admin');
    }
}
