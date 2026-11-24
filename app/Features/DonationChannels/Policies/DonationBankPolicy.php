<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Policies;

use App\Features\DonationChannels\Models\DonationBank;
use App\Shared\Auth\AccountActor;

/**
 * Global admins alone maintain the country bank catalog; every other role,
 * moderators included, may neither view nor change its entries.
 */
class DonationBankPolicy
{
    public function viewAny(?AccountActor $user): bool
    {
        return $user instanceof AccountActor && $user->hasGlobalAdminAccess();
    }

    public function view(?AccountActor $user, DonationBank $bank): bool
    {
        return $this->viewAny($user);
    }

    public function create(?AccountActor $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(?AccountActor $user, DonationBank $bank): bool
    {
        return $this->viewAny($user);
    }

    public function delete(?AccountActor $user, DonationBank $bank): bool
    {
        return $this->viewAny($user);
    }

    public function deleteAny(?AccountActor $user): bool
    {
        return $this->viewAny($user);
    }
}
