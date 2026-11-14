<?php

declare(strict_types=1);

namespace App\Features\Ai\Policies;

use App\Features\Ai\Models\AiModelPricing;
use App\Shared\Auth\AccountActor;

class AiModelPricingPolicy
{
    public function delete(AccountActor $user, AiModelPricing $pricing): bool
    {
        return $user->hasRole('super_admin');
    }

    public function deleteAny(AccountActor $user): bool
    {
        return $user->hasRole('super_admin');
    }
}
