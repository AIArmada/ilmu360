<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use AIArmada\Affiliates\Models\AffiliateClaim;
use App\Features\Identity\Api\SignupAttributions;
use App\Models\User;
use App\Support\Share\ShareProfileScope;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class LinkGuestSignupAttributionsAction
{
    use AsAction;

    public function __construct(private SignupAttributions $signupAttributions) {}

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

            $this->signupAttributions->linkGuestReferrals(
                (string) $lockedClaim->affiliate_id,
                (string) $lockedClaim->claimant_id,
            );
        }, 3);
    }
}
