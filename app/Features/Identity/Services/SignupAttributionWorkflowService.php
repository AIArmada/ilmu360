<?php

declare(strict_types=1);

namespace App\Features\Identity\Services;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Referrals\Enums\ReferralInviteStatus;
use AIArmada\Referrals\Enums\ReferralProgramStatus;
use AIArmada\Referrals\Models\ReferralInvite;
use AIArmada\Referrals\Support\ReferralContactNormalizer;
use App\Features\Identity\Api\RecordSignupAttributionInput;
use App\Features\Identity\Api\SignupAttributions;
use App\Features\Identity\Api\SignupAttributionSnapshot;
use App\Features\Identity\Api\SignupAttributionSource;
use App\Features\Identity\Api\SignupAttributionSourceType;
use App\Features\Identity\Api\SignupAttributionStatus;
use App\Features\Identity\Api\SignupAttributionWorkflow;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Auth\AccountProfile;
use App\Shared\Auth\SignupAttributionContext;
use App\Shared\Auth\UserMorphType;
use App\Shared\ShareTracking\GuestClaims;
use App\Shared\ShareTracking\GuestClaimSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class SignupAttributionWorkflowService implements SignupAttributionWorkflow
{
    public function __construct(
        private AccountDirectory $accounts,
        private SignupAttributions $signupAttributions,
        private GuestClaims $guestClaims,
        private UserMorphType $userMorphType,
        private ReferralContactNormalizer $contactNormalizer,
    ) {}

    public function recordForNewAccount(
        string $accountId,
        SignupAttributionContext $context,
        bool $wasRecentlyCreated,
    ): ?SignupAttributionSnapshot {
        return DB::transaction(function () use ($accountId, $context, $wasRecentlyCreated): ?SignupAttributionSnapshot {
            $this->accounts->lockForUpdate($accountId);
            $existing = $this->signupAttributions->findForAccount($accountId);

            if ($existing instanceof SignupAttributionSnapshot || ! $wasRecentlyCreated) {
                return $existing;
            }

            $profile = $this->accounts->profile($accountId);

            if (! $profile instanceof AccountProfile) {
                return null;
            }

            $source = is_array($context->affiliateSource)
                ? SignupAttributionSource::fromArray($context->affiliateSource)
                : new SignupAttributionSource(SignupAttributionSourceType::Direct);
            $source = $this->resolveGuestReferrer($source);
            $invite = $this->matchingInvite($context->referralInviteId, $accountId, $profile);

            if ($source->referrerUserId === $accountId) {
                $source = new SignupAttributionSource(SignupAttributionSourceType::Direct);
            }

            $attribution = $this->signupAttributions->record(new RecordSignupAttributionInput(
                accountId: $accountId,
                sourceType: $invite instanceof ReferralInvite ? SignupAttributionSourceType::ReferralInvite : $source->type,
                status: $invite instanceof ReferralInvite ? SignupAttributionStatus::Pending : SignupAttributionStatus::Confirmed,
                referrerAccountId: $invite instanceof ReferralInvite ? $invite->referrer_id : $source->referrerUserId,
                affiliateId: $invite instanceof ReferralInvite ? null : $source->affiliateId,
                affiliateLinkId: $invite instanceof ReferralInvite ? null : $source->affiliateLinkId,
                affiliateAttributionId: $invite instanceof ReferralInvite ? null : $source->affiliateAttributionId,
                referralInviteId: $invite?->getKey(),
                inviteeContactHash: $invite?->invitee_hash,
                signedUpAt: CarbonImmutable::instance($profile->createdAt),
            ));

            if ($invite instanceof ReferralInvite) {
                return $this->confirmForAccount($accountId) ?? $attribution;
            }

            return $attribution;
        }, 3);
    }

    public function confirmForAccount(string $accountId): ?SignupAttributionSnapshot
    {
        return DB::transaction(function () use ($accountId): ?SignupAttributionSnapshot {
            if (! $this->accounts->exists($accountId)) {
                return null;
            }

            $this->accounts->lockForUpdate($accountId);
            $profile = $this->accounts->profile($accountId);

            if (! $profile instanceof AccountProfile) {
                return null;
            }

            $attribution = $this->signupAttributions->lockForAccount($accountId);

            if (! $attribution instanceof SignupAttributionSnapshot
                || $attribution->sourceType !== SignupAttributionSourceType::ReferralInvite
                || $attribution->status !== SignupAttributionStatus::Pending) {
                return $attribution;
            }

            foreach ([[$profile->email, $profile->emailVerified], [$profile->phone, $profile->phoneVerified]] as [$contact, $verified]) {
                if (! $verified || ! is_string($contact) || trim($contact) === '') {
                    continue;
                }

                try {
                    $matches = is_string($attribution->inviteeContactHash)
                        && hash_equals($attribution->inviteeContactHash, $this->contactNormalizer->hash($contact));
                } catch (InvalidArgumentException) {
                    continue;
                }

                if ($matches) {
                    return $this->signupAttributions->confirm($attribution->id) ?? $attribution;
                }
            }

            return $attribution;
        }, 3);
    }

    public function linkGuestReferralsForClaim(
        string $claimId,
        string $affiliateId,
        string $claimantType,
        string $claimantId,
    ): int {
        return DB::transaction(function () use ($claimId, $affiliateId, $claimantType, $claimantId): int {
            $claim = $this->guestClaims->lockClaim($claimId);

            if (! $claim instanceof GuestClaimSnapshot
                || ! $claim->active
                || $claim->affiliateId !== $affiliateId
                || $claim->claimantType !== $this->userMorphType->morphClass()
                || $claimantType !== $claim->claimantType
                || $claimantId !== $claim->claimantId) {
                return 0;
            }

            return $this->signupAttributions->linkGuestReferrals($affiliateId, $claimantId);
        }, 3);
    }

    private function resolveGuestReferrer(SignupAttributionSource $source): SignupAttributionSource
    {
        if ($source->type !== SignupAttributionSourceType::ShareGuest || $source->affiliateId === null) {
            return $source;
        }

        $referrerId = $this->guestClaims->activeClaimantId($source->affiliateId, lockAffiliate: true);

        return new SignupAttributionSource(
            $source->type,
            $referrerId,
            $source->affiliateId,
            $source->affiliateLinkId,
            $source->affiliateAttributionId,
        );
    }

    private function matchingInvite(?string $inviteId, string $accountId, AccountProfile $profile): ?ReferralInvite
    {
        if ($inviteId === null) {
            return null;
        }

        $invite = OwnerContext::withOwner(null, fn () => ReferralInvite::query()->with('program')->whereKey($inviteId)->first());
        $program = $invite?->program;
        $signupAt = $profile->createdAt;

        if ($invite === null
            || $program === null
            || $program->owner_type !== null || $program->owner_id !== null
            || $program->status !== ReferralProgramStatus::Active
            || ! $program->isActiveAt($signupAt)
            || $invite->status !== ReferralInviteStatus::Pending
            || $invite->isExpiredAt($signupAt)
            || $invite->referrer_type !== $this->userMorphType->morphClass()
            || (string) $invite->referrer_id === $accountId
            || ! $this->accounts->exists((string) $invite->referrer_id)) {
            return null;
        }

        foreach ([$profile->email, $profile->phone] as $contact) {
            if (! is_string($contact) || trim($contact) === '') {
                continue;
            }

            try {
                if (hash_equals($invite->invitee_hash, $this->contactNormalizer->hash($contact))) {
                    return $invite;
                }
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return null;
    }
}
