<?php

declare(strict_types=1);

namespace App\Actions\ShareTracking;

use App\Features\ShareTracking\Api\GuestShareClaims;
use App\Models\User;
use App\Shared\ShareTracking\ShareGuestClaimResult;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Root adapter for the ShareTracking-owned guest claim orchestration.
 *
 * The controller and existing callers keep this camel-cased entry point; the
 * feature API receives only scalars and never a mutable model.
 */
final readonly class ClaimGuestShareProfileAction
{
    use AsAction;

    public function __construct(
        private GuestShareClaims $guestShareClaims,
    ) {}

    public function handle(User $claimant, string $presentedCapability, bool $confirmedConsent): ShareGuestClaimResult
    {
        return $this->guestShareClaims->claim(
            (string) $claimant->getKey(),
            $claimant->getMorphClass(),
            $presentedCapability,
            $confirmedConsent,
        );
    }
}
