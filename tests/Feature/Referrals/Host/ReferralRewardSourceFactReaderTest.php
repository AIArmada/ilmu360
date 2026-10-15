<?php

declare(strict_types=1);

use AIArmada\Referrals\Actions\AcceptReferralInvite;
use AIArmada\Referrals\Actions\CreateReferralInvite;
use AIArmada\Referrals\Actions\CreateReferralProgram;
use AIArmada\Referrals\Actions\QualifyReferral;
use AIArmada\Referrals\Enums\ReferralProgramStatus;
use AIArmada\Rewards\Contracts\RewardSourceFactReader;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardVerificationLevel;
use App\Models\Registration;
use App\Models\User;
use App\Support\Rewards\ActivityRewardSourceFactReader;
use App\Support\Rewards\ReferralRewardSourceFactReader;
use App\Support\Rewards\ShareAttributionRewardSourceFactReader;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('maps both referral reward sides to the live accepted registration fact', function (): void {
    $referrer = User::factory()->create();
    $invitee = User::factory()->create();
    $program = CreateReferralProgram::run([
        'name' => 'Reader contract programme',
        'qualifier_outcome' => 'first_accepted_registration_or_staff_verified_checkin',
        'invite_expiry_days' => 30,
        'status' => ReferralProgramStatus::Active,
        'referrer_reward' => ['driver' => 'reward_points', 'points' => 17, 'family_key' => 'referral-reader'],
        'referee_reward' => ['driver' => 'voucher', 'amount_minor' => 1000, 'currency' => 'MYR', 'voucher' => ['type' => 'fixed', 'value' => 1000]],
    ]);
    $creation = CreateReferralInvite::run($program, $referrer, (string) $invitee->email, (string) $referrer->email);
    $invite = AcceptReferralInvite::run((string) $creation->token, $invitee, (string) $invitee->email);
    $registeredAt = CarbonImmutable::now('UTC')->addMinute();
    $registration = Registration::factory()->forRegistrant($invitee)->create([
        'status' => 'confirmed',
        'registered_at' => $registeredAt,
    ]);
    $sourceKey = ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey());
    $qualification = QualifyReferral::run($invite, $sourceKey, $registeredAt);
    $reader = app(ReferralRewardSourceFactReader::class);

    $referrerFact = $reader->read('referrals:qualified:'.$invite->getKey().':referrer');
    $refereeFact = $reader->read('referrals:qualified:'.$invite->getKey().':referee');

    expect($qualification->invite->qualification_business_key)->toBe($sourceKey)
        ->and($referrerFact)->not->toBeNull()
        ->and($referrerFact?->outcome)->toBe('referral_qualified')
        ->and($referrerFact?->actorId)->toBe((string) $invitee->getKey())
        ->and($referrerFact?->sharerId)->toBe((string) $referrer->getKey())
        ->and($referrerFact?->actorRole)->toBe(RewardBeneficiaryRole::Participant)
        ->and($referrerFact?->isClaimedIdentity)->toBeTrue()
        ->and($referrerFact?->isAttributed)->toBeTrue()
        ->and($referrerFact?->isValid)->toBeTrue()
        ->and($referrerFact?->verification)->toBe(RewardVerificationLevel::Accepted)
        ->and($referrerFact?->occurredAt->equalTo($qualification->invite->qualified_at))->toBeTrue()
        ->and($refereeFact?->sharerId)->toBe((string) $referrer->getKey())
        ->and($refereeFact?->actorId)->toBe((string) $invitee->getKey())
        ->and($refereeFact?->isValid)->toBeTrue();
});

it('keeps the activity, share-outcome and referral source key namespaces isolated', function (): void {
    $reader = app(RewardSourceFactReader::class);
    $activityReader = app(ActivityRewardSourceFactReader::class);
    $shareReader = app(ShareAttributionRewardSourceFactReader::class);
    $referralReader = app(ReferralRewardSourceFactReader::class);
    $referralKey = 'referrals:qualified:'.Str::uuid().':referrer';

    expect($activityReader->read($referralKey))->toBeNull()
        ->and($shareReader->read($referralKey))->toBeNull()
        ->and($referralReader->read('activity:event_checkin:checkin:'.Str::uuid()))->toBeNull()
        ->and($referralReader->read('share-outcome:event_checkin:checkin:'.Str::uuid()))->toBeNull()
        ->and($reader->read($referralKey))->toBeNull()
        ->and($reader->read('referrals:qualified:'.Str::uuid().':unknown'))->toBeNull();
});
