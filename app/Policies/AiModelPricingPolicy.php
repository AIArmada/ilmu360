<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AiModelPricing;
use App\Models\User;

class AiModelPricingPolicy
{
    public function delete(User $user, AiModelPricing $pricing): bool
    {
        return $user->hasRole('super_admin');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole('super_admin');
    }
}
