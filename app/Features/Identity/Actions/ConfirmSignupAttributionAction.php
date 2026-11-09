<?php

declare(strict_types=1);

namespace App\Features\Identity\Actions;

use App\Features\Identity\Api\SignupAttributionSnapshot;
use App\Features\Identity\Api\SignupAttributionStatus;
use App\Features\Identity\Models\SignupAttribution;
use App\Features\Identity\Services\SignupAttributionSnapshots;

/**
 * Confirm a pending signup attribution after contact verification.
 */
final class ConfirmSignupAttributionAction
{
    public function handle(string $attributionId): ?SignupAttributionSnapshot
    {
        SignupAttribution::query()
            ->whereKey($attributionId)
            ->where('status', SignupAttributionStatus::Pending->value)
            ->update([
                'status' => SignupAttributionStatus::Confirmed->value,
                'confirmed_at' => now(),
                'updated_at' => now(),
            ]);

        $attribution = SignupAttribution::query()->find($attributionId);

        return $attribution instanceof SignupAttribution
            ? SignupAttributionSnapshots::fromModel($attribution)
            : null;
    }
}
