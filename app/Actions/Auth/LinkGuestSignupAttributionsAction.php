<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\SignupAttributionSourceType;
use App\Models\ShareGuestClaim;
use App\Models\SignupAttribution;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class LinkGuestSignupAttributionsAction
{
    use AsAction;

    public function handle(ShareGuestClaim $claim): void
    {
        DB::transaction(function () use ($claim): void {
            $lockedClaim = ShareGuestClaim::query()->whereKey($claim->getKey())->lockForUpdate()->first();

            if (! $lockedClaim instanceof ShareGuestClaim || ! $lockedClaim->isActive()) {
                return;
            }

            SignupAttribution::query()
                ->where('source_type', SignupAttributionSourceType::ShareGuest->value)
                ->where('affiliate_id', $lockedClaim->guest_affiliate_id)
                ->whereNull('referrer_user_id')
                ->where('user_id', '<>', $lockedClaim->claimant_user_id)
                ->update([
                    'referrer_user_id' => $lockedClaim->claimant_user_id,
                    'updated_at' => now(),
                ]);
        }, 3);
    }
}
