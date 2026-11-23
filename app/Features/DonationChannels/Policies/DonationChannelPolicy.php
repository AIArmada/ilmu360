<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Policies;

use App\Features\DonationChannels\Api\Enums\DonationChannelStatus;
use App\Features\DonationChannels\Models\DonationChannel;
use App\Shared\Auth\AccountActor;

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
        return $user->hasGlobalAdminAccess();
    }

    public function update(AccountActor $user, DonationChannel $donationChannel): bool
    {
        return $user->hasGlobalAdminAccess();
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

    public function restoreAny(AccountActor $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function forceDelete(AccountActor $user, DonationChannel $donationChannel): bool
    {
        return $user->hasRole('super_admin');
    }

    public function forceDeleteAny(AccountActor $user): bool
    {
        return $user->hasRole('super_admin');
    }
}
