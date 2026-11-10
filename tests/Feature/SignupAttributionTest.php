<?php

declare(strict_types=1);

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateClaim;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Referrals\Actions\CreateReferralInvite;
use AIArmada\Referrals\Actions\CreateReferralProgram;
use AIArmada\Referrals\Actions\RevokeReferralInvite;
use AIArmada\Referrals\Enums\ReferralProgramStatus;
use AIArmada\Referrals\Models\ReferralInvite;
use AIArmada\Referrals\Models\ReferralReward;
use App\Actions\Referrals\ResolveReferralInviteLandingAction;
use App\Actions\ShareTracking\ClaimGuestShareProfileAction;
use App\Enums\ShareGuestClaimResult;
use App\Features\Identity\Api\SignupAttributions;
use App\Features\Identity\Api\SignupAttributionSnapshot;
use App\Features\Identity\Api\SignupAttributionSource;
use App\Features\Identity\Api\SignupAttributionSourceType;
use App\Features\Identity\Api\SignupAttributionStatus;
use App\Features\Identity\Api\SignupAttributionWorkflow;
use App\Features\Identity\Models\SignupAttribution;
use App\Models\User;
use App\Shared\Auth\SignupAttributionContext;
use App\Shared\Auth\SignupAttributionContextResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

function signupAttributionRecordFor(User $user): SignupAttribution
{
    return SignupAttribution::query()->where('user_id', $user->getKey())->firstOrFail();
}

/** @return list<string> */
function confirmedSignupReferralAccountIds(User $referrer): array
{
    return app(SignupAttributions::class)->confirmedReferralAccountIds((string) $referrer->getKey());
}

function recordSignupAttribution(User $user, SignupAttributionContext $context = new SignupAttributionContext, bool $wasRecentlyCreated = true): ?SignupAttributionSnapshot
{
    return app(SignupAttributionWorkflow::class)->recordForNewAccount((string) $user->getKey(), $context, $wasRecentlyCreated);
}

function confirmSignupAttribution(User $user): ?SignupAttributionSnapshot
{
    return app(SignupAttributionWorkflow::class)->confirmForAccount((string) $user->getKey());
}

function signupAcquisitionContext(?SignupAttributionSource $source = null, ?ReferralInvite $invite = null): SignupAttributionContext
{
    return new SignupAttributionContext(
        referralInviteId: $invite?->getKey(),
        affiliateSource: $source?->toArray(),
    );
}

/** @return array{ReferralInvite, User, string} */
function signupAcquisitionInvite(string $contact): array
{
    $referrer = User::factory()->emailOnly()->create();
    $program = CreateReferralProgram::run([
        'name' => 'Signup acquisition invitations',
        'status' => ReferralProgramStatus::Active,
        'qualifier_outcome' => 'first_accepted_registration_or_staff_verified_checkin',
        'invite_expiry_days' => 30,
        'referrer_reward' => ['driver' => 'reward_points', 'points' => 5, 'family_key' => 'signup-test'],
        'referee_reward' => ['driver' => 'reward_points', 'points' => 5, 'family_key' => 'signup-test'],
    ]);
    $created = CreateReferralInvite::run($program, $referrer, $contact, (string) $referrer->email);

    return [$created->invite, $referrer, (string) $created->token];
}

it('freezes a direct signup so later links and invitation confirmation cannot assign a referrer', function (): void {
    $user = User::factory()->unverified()->emailOnly()->create();
    $referrer = User::factory()->create();
    $original = recordSignupAttribution($user);
    $lateSource = new SignupAttributionSource(SignupAttributionSourceType::ShareMember, (string) $referrer->getKey());

    $replayed = recordSignupAttribution($user, signupAcquisitionContext($lateSource));
    confirmSignupAttribution($user);

    expect($replayed?->id)->toBe($original?->id);
    expect(signupAttributionRecordFor($user))->source_type->toBe(SignupAttributionSourceType::Direct)
        ->status->toBe(SignupAttributionStatus::Confirmed)
        ->referrer_user_id->toBeNull();
    expect(SignupAttribution::query()->where('user_id', $user->getKey())->count())->toBe(1);
});

it('retains the commercial signup referrer when a later source would credit a different user', function (): void {
    $alice = User::factory()->create();
    $carol = User::factory()->create();
    $bob = User::factory()->create();
    $source = new SignupAttributionSource(SignupAttributionSourceType::CommercialAffiliate, (string) $alice->getKey());
    $original = recordSignupAttribution($bob, signupAcquisitionContext($source));

    recordSignupAttribution($bob, signupAcquisitionContext(
        new SignupAttributionSource(SignupAttributionSourceType::CommercialAffiliate, (string) $carol->getKey()),
    ));

    expect(signupAttributionRecordFor($bob)->referrer_user_id)->toBe((string) $alice->getKey());
    expect(confirmedSignupReferralAccountIds($alice))->toBe([(string) $bob->getKey()]);
    expect(confirmedSignupReferralAccountIds($carol))->toBe([]);
    expect(AffiliateConversion::query()->count())->toBe(0);
    expect(ReferralReward::query()->count())->toBe(0);
});

it('does not infer signup attribution when an existing account accepts an invitation', function (): void {
    $existingUser = User::factory()->emailOnly()->create(['email' => 'existing-invitee@example.test'])->fresh();
    [$invite, , $token] = signupAcquisitionInvite('existing-invitee@example.test');
    $result = app(ResolveReferralInviteLandingAction::class)->handle($token, $existingUser);

    expect($result['outcome'])->toBe('accepted');
    expect(recordSignupAttribution($existingUser, signupAcquisitionContext(invite: $invite), false))->toBeNull();
    expect(confirmSignupAttribution($existingUser))->toBeNull();
    expect(SignupAttribution::query()->count())->toBe(0);
});

it('confirms the captured inviter only after the invited email is verified without issuing referral rewards', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 12:00:00', 'UTC'));
    [$invite, $referrer] = signupAcquisitionInvite('new-invitee@example.test');
    $user = User::factory()->unverified()->emailOnly()->create(['email' => 'new-invitee@example.test']);
    $attribution = recordSignupAttribution($user, signupAcquisitionContext(invite: $invite));

    expect($attribution)->status->toBe(SignupAttributionStatus::Pending)->confirmedAt->toBeNull();
    expect(confirmedSignupReferralAccountIds($referrer))->toBe([]);

    $this->travel(5)->minutes();
    $user->forceFill(['email_verified_at' => now()])->save();

    expect(signupAttributionRecordFor($user))->status->toBe(SignupAttributionStatus::Confirmed)
        ->referrer_user_id->toBe((string) $referrer->getKey())
        ->confirmed_at->toEqual(CarbonImmutable::parse('2026-10-28 12:05:00', 'UTC'));
    expect(confirmedSignupReferralAccountIds($referrer))->toBe([(string) $user->getKey()]);
    expect($invite->refresh()->status->value)->toBe('pending');
    expect(ReferralReward::query()->count())->toBe(0);
});

it('requires verification of the captured phone even when an unrelated email is verified', function (): void {
    [$invite] = signupAcquisitionInvite('+60123456789');
    $user = User::factory()->create(['phone' => '+60123456789', 'phone_verified_at' => null]);
    $attribution = recordSignupAttribution($user, signupAcquisitionContext(invite: $invite));

    expect($attribution?->status)->toBe(SignupAttributionStatus::Pending);

    $user->forceFill(['phone_verified_at' => now()])->save();

    expect(signupAttributionRecordFor($user)->status)->toBe(SignupAttributionStatus::Confirmed);
});

it('prefers a valid contact-matching invite over the captured share source', function (): void {
    [$invite, $inviter] = signupAcquisitionInvite('matching-invite@example.test');
    $otherSharer = User::factory()->create();
    $user = User::factory()->emailOnly()->create(['email' => 'matching-invite@example.test']);
    $source = new SignupAttributionSource(SignupAttributionSourceType::ShareMember, (string) $otherSharer->getKey());

    $attribution = recordSignupAttribution($user, signupAcquisitionContext($source, $invite));

    expect($attribution)->sourceType->toBe(SignupAttributionSourceType::ReferralInvite)
        ->referrerAccountId->toBe((string) $inviter->getKey())
        ->status->toBe(SignupAttributionStatus::Confirmed);
    expect(confirmedSignupReferralAccountIds($otherSharer))->toBe([]);
});

it('uses the captured link when the invitation targets another contact', function (): void {
    [$invite] = signupAcquisitionInvite('someone-else@example.test');
    $sharer = User::factory()->create();
    $user = User::factory()->emailOnly()->create();
    $source = new SignupAttributionSource(SignupAttributionSourceType::ShareMember, (string) $sharer->getKey());

    $attribution = recordSignupAttribution($user, signupAcquisitionContext($source, $invite));

    expect($attribution)->sourceType->toBe(SignupAttributionSourceType::ShareMember)
        ->referrerAccountId->toBe((string) $sharer->getKey())
        ->referralInviteId->toBeNull();
});

it('freezes a direct signup when a previously captured invite expires before account creation', function (): void {
    $this->freezeTime();
    [$invite] = signupAcquisitionInvite('expired-signup@example.test');
    $this->travel(31)->days();
    $user = User::factory()->emailOnly()->create(['email' => 'expired-signup@example.test']);

    $attribution = recordSignupAttribution($user, signupAcquisitionContext(invite: $invite));

    expect($attribution)->sourceType->toBe(SignupAttributionSourceType::Direct)->referrerAccountId->toBeNull();
});

it('refuses revoked invitations and invalid public tokens as signup evidence', function (): void {
    [$invite] = signupAcquisitionInvite('revoked-signup@example.test');
    RevokeReferralInvite::run($invite, 'signup-test');
    $user = User::factory()->emailOnly()->create(['email' => 'revoked-signup@example.test']);
    $attribution = recordSignupAttribution($user, signupAcquisitionContext(invite: $invite));
    $captured = app(SignupAttributionContextResolver::class)->resolve(Request::create('/', 'POST', ['referral_invite_token' => str_repeat('z', 32)]));

    expect($attribution?->sourceType)->toBe(SignupAttributionSourceType::Direct);
    expect($captured->referralInviteId)->toBeNull();
});

it('links guest signup history through an explicit claim while preserving the source and excluding self referrals', function (): void {
    $payload = $this->getJson(route('share.payload', [
        'url' => route('events.index'), 'text' => 'Shared page', 'title' => 'Signup guest share',
    ]))->assertOk()->json();
    $link = AffiliateLink::query()->where('custom_slug', $payload['tracking_token'])->firstOrFail();
    $guestSource = new SignupAttributionSource(SignupAttributionSourceType::ShareGuest, affiliateId: (string) $link->affiliate_id, affiliateLinkId: (string) $link->getKey());
    $claimant = User::factory()->create();
    $invitee = User::factory()->create();
    $selfAttribution = recordSignupAttribution($claimant, signupAcquisitionContext($guestSource));
    $attribution = recordSignupAttribution($invitee, signupAcquisitionContext($guestSource));

    expect($attribution?->referrerAccountId)->toBeNull();
    expect(AffiliateClaim::query()->count())->toBe(0);

    $result = ClaimGuestShareProfileAction::run($claimant, $payload['guest_claim_capability'], true);

    expect($result)->toBe(ShareGuestClaimResult::Claimed);
    expect(signupAttributionRecordFor($invitee))->source_type->toBe(SignupAttributionSourceType::ShareGuest)
        ->affiliate_id->toBe((string) $link->affiliate_id)
        ->referrer_user_id->toBe((string) $claimant->getKey());
    expect(signupAttributionRecordFor($claimant)->referrer_user_id)->toBeNull();
    expect(Affiliate::query()->whereKey($link->affiliate_id)->firstOrFail()->owner_id)->toBeNull();
    expect(confirmedSignupReferralAccountIds($claimant))->toBe([(string) $invitee->getKey()]);

    $laterSignup = User::factory()->create();
    $laterAttribution = recordSignupAttribution($laterSignup, signupAcquisitionContext($guestSource));

    expect($laterAttribution?->referrerAccountId)->toBe((string) $claimant->getKey());
});

it('rejects attempts to rewrite the recorded signup source through a model update', function (): void {
    $user = User::factory()->create();
    recordSignupAttribution($user);
    $attribution = signupAttributionRecordFor($user);

    expect(fn () => $attribution->update(['source_type' => SignupAttributionSourceType::ShareMember]))
        ->toThrow(LogicException::class);
    expect($attribution->fresh()->source_type)->toBe(SignupAttributionSourceType::Direct);
});

it('does not confirm an invite using verification that exists only on a stale user instance', function (): void {
    [$invite] = signupAcquisitionInvite('stale-signup@example.test');
    $user = User::factory()->unverified()->emailOnly()->create(['email' => 'stale-signup@example.test']);
    $attribution = recordSignupAttribution($user, signupAcquisitionContext(invite: $invite));
    $staleUser = $user->fresh();
    $staleUser->forceFill(['email_verified_at' => now()]);

    confirmSignupAttribution($staleUser);

    expect(signupAttributionRecordFor($user))->status->toBe(SignupAttributionStatus::Pending)->confirmed_at->toBeNull();
});
