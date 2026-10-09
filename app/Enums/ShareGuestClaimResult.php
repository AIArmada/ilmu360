<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Server-side guest-claim decision.
 *
 * ClaimedByOther and ExpiredOrInvalid render identically to the requester
 * through publicCode(): the presenting browser learns nothing about
 * which valid history another account owns. AlreadyClaimedByYou replays
 * success to the original claimant without touching any row or effect.
 */
enum ShareGuestClaimResult: string
{
    case Claimed = 'claimed';
    case AlreadyClaimedByYou = 'already_claimed_by_you';
    case ClaimedByOther = 'claimed_by_other';
    case ExpiredOrInvalid = 'expired_or_invalid';
    case RecipientIneligible = 'recipient_ineligible';

    /**
     * Wire code for API/JSON responses. The two refusal decisions share
     * one neutral code so a rival holding a consumed capability cannot
     * distinguish "claimed by someone else" from "never valid".
     */
    public function publicCode(): string
    {
        return match ($this) {
            self::ClaimedByOther,
            self::ExpiredOrInvalid => 'unverified',
            default => $this->value,
        };
    }
}
