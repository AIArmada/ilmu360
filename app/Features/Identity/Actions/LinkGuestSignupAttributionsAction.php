<?php

declare(strict_types=1);

namespace App\Features\Identity\Actions;

use App\Features\Identity\Api\SignupAttributionSourceType;
use App\Features\Identity\Models\SignupAttribution;

/**
 * Reassign guest-source signup receipts to the account that claimed the
 * underlying share profile.
 */
final class LinkGuestSignupAttributionsAction
{
    public function handle(string $affiliateId, string $referrerAccountId): int
    {
        return SignupAttribution::query()
            ->where('source_type', SignupAttributionSourceType::ShareGuest->value)
            ->where('affiliate_id', $affiliateId)
            ->whereNull('referrer_user_id')
            ->where('user_id', '<>', $referrerAccountId)
            ->update([
                'referrer_user_id' => $referrerAccountId,
                'updated_at' => now(),
            ]);
    }
}
