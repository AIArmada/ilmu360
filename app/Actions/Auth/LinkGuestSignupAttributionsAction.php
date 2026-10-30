<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use AIArmada\Affiliates\Models\AffiliateClaim;
use App\Enums\SignupAttributionSourceType;
use App\Models\SignupAttribution;
use App\Models\User;
use App\Support\Share\ShareProfileScope;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class LinkGuestSignupAttributionsAction
{
    use AsAction;

    public function handle(AffiliateClaim $claim): void
    {
        DB::transaction(function () use ($claim): void {
            $lockedClaim = ShareProfileScope::guestClaims()->whereKey($claim->getKey())->lockForUpdate()->first();

            if (! $lockedClaim instanceof AffiliateClaim || ! $lockedClaim->isActive()) {
                return;
            }

            // Host invariant: share claimants are users. A non-user claimant
            // (possible at the package level) backfills nothing here.
            if ($lockedClaim->claimant_type !== (new User)->getMorphClass()) {
                return;
            }

            SignupAttribution::query()
                ->where('source_type', SignupAttributionSourceType::ShareGuest->value)
                ->where('affiliate_id', $lockedClaim->affiliate_id)
                ->whereNull('referrer_user_id')
                ->where('user_id', '<>', $lockedClaim->claimant_id)
                ->update([
                    'referrer_user_id' => $lockedClaim->claimant_id,
                    'updated_at' => now(),
                ]);
        }, 3);
    }
}
