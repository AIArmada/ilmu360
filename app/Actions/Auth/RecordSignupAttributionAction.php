<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateClaim;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScope;
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
use App\Models\User;
use App\Support\Share\ShareProfileScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class RecordSignupAttributionAction
{
    use AsAction;

    public function __construct(
        private CaptureSignupAttributionContextAction $captureContext,
        private ConfirmSignupAttributionAction $confirmAttribution,
        private ReferralContactNormalizer $contactNormalizer,
        private SignupAttributions $signupAttributions,
    ) {}

    /** @param array{referral_invite_id: ?string, affiliate_source: array<string, mixed>|null}|null $context */
    public function handle(User $user, ?Request $request = null, ?array $context = null): ?SignupAttributionSnapshot
    {
        return DB::transaction(function () use ($user, $request, $context): ?SignupAttributionSnapshot {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $existing = $this->signupAttributions->findForAccount((string) $user->getKey());

            if ($existing instanceof SignupAttributionSnapshot || ! $user->wasRecentlyCreated) {
                return $existing;
            }

            $context ??= $this->captureContext->handle($request);
            $invite = $this->matchingInvite($context['referral_invite_id'], $user);
            $source = is_array($context['affiliate_source'])
                ? SignupAttributionSource::fromArray($context['affiliate_source'])
                : new SignupAttributionSource(SignupAttributionSourceType::Direct);
            $source = $this->resolveGuestReferrer($source);

            if ($source->referrerUserId === (string) $user->getKey()) {
                $source = new SignupAttributionSource(SignupAttributionSourceType::Direct);
            }

            $attribution = $this->signupAttributions->record(new RecordSignupAttributionInput(
                accountId: (string) $user->getKey(),
                sourceType: $invite !== null ? SignupAttributionSourceType::ReferralInvite : $source->type,
                status: $invite !== null ? SignupAttributionStatus::Pending : SignupAttributionStatus::Confirmed,
                referrerAccountId: $invite !== null ? $invite->referrer_id : $source->referrerUserId,
                affiliateId: $invite !== null ? null : $source->affiliateId,
                affiliateLinkId: $invite !== null ? null : $source->affiliateLinkId,
                affiliateAttributionId: $invite !== null ? null : $source->affiliateAttributionId,
                referralInviteId: $invite?->getKey(),
                inviteeContactHash: $invite?->invitee_hash,
                signedUpAt: $user->created_at?->toImmutable(),
            ));

            if ($invite !== null) {
                return $this->confirmAttribution->handle($user) ?? $attribution;
            }

            return $attribution;
        }, 3);
    }

    private function resolveGuestReferrer(SignupAttributionSource $source): SignupAttributionSource
    {
        if ($source->type !== SignupAttributionSourceType::ShareGuest || $source->affiliateId === null) {
            return $source;
        }

        Affiliate::query()->withoutGlobalScope(OwnerScope::class)
            ->whereKey($source->affiliateId)->lockForUpdate()->first();

        $claim = ShareProfileScope::guestClaims()->where('affiliate_id', $source->affiliateId)->first();
        $referrerId = $claim instanceof AffiliateClaim && $claim->isActive()
            && $claim->claimant_type === (new User)->getMorphClass()
            && User::query()->whereKey($claim->claimant_id)->exists()
                ? (string) $claim->claimant_id
                : null;

        return new SignupAttributionSource(
            $source->type,
            $referrerId,
            $source->affiliateId,
            $source->affiliateLinkId,
            $source->affiliateAttributionId,
        );
    }

    private function matchingInvite(?string $inviteId, User $user): ?ReferralInvite
    {
        if ($inviteId === null) {
            return null;
        }

        $invite = OwnerContext::withOwner(null, fn () => ReferralInvite::query()->with('program')->whereKey($inviteId)->first());
        $program = $invite?->program;
        $signupAt = $user->created_at;

        if ($invite === null
            || $program === null
            || $program->owner_type !== null || $program->owner_id !== null
            || $program->status !== ReferralProgramStatus::Active
            || ! $program->isActiveAt($signupAt)
            || $invite->status !== ReferralInviteStatus::Pending
            || $invite->isExpiredAt($signupAt)
            || $invite->referrer_type !== $user->getMorphClass()
            || (string) $invite->referrer_id === (string) $user->getKey()
            || ! User::query()->whereKey($invite->referrer_id)->exists()) {
            return null;
        }

        foreach ([$user->email, $user->phone] as $contact) {
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
