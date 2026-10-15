<?php

declare(strict_types=1);

use AIArmada\Referrals\Actions\AcceptReferralInvite;
use AIArmada\Referrals\Actions\CreateReferralInvite;
use AIArmada\Referrals\Actions\CreateReferralProgram;
use AIArmada\Referrals\Enums\ReferralInviteStatus;
use AIArmada\Referrals\Enums\ReferralProgramStatus;
use AIArmada\Referrals\Enums\ReferralRewardSide;
use AIArmada\Referrals\Enums\ReferralRewardStatus;
use AIArmada\Referrals\Models\ReferralInvite;
use AIArmada\Referrals\Models\ReferralReward;
use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Actions\TransitionRewardProgramStatusAction;
use AIArmada\Rewards\Contracts\RewardSourceFactReader;
use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Vouchers\Models\Voucher;
use AIArmada\Vouchers\States\Expired;
use App\Actions\Referrals\QualifyReferralFromShareOutcomeAction;
use App\Actions\Referrals\ReverseReferralRewardsForShareOutcomeAction;
use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Enums\DawahShareOutcomeType;
use App\Jobs\Referrals\QualifyReferralFromShareOutcomeJob;
use App\Jobs\Referrals\ReverseReferralRewardsForShareOutcomeJob;
use App\Models\EventCheckin;
use App\Models\Registration;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

it('has no referral effect for a qualifying outcome without an accepted invite or programme', function (): void {
    Queue::fake([
        QualifyReferralFromShareOutcomeJob::class,
    ]);
    $invitee = User::factory()->create();
    $registeredAt = CarbonImmutable::now('UTC')->addMinute();
    $registration = Registration::factory()->forRegistrant($invitee)->create([
        'status' => 'confirmed',
        'registered_at' => $registeredAt,
    ]);
    $sourceKey = ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey());

    app(ShareTrackingService::class)->recordOutcome(
        DawahShareOutcomeType::EventRegistration,
        $sourceKey,
        actor: $invitee,
        occurredAt: $registeredAt,
    );
    app(QualifyReferralFromShareOutcomeAction::class)->handle($sourceKey);

    expect(ReferralInvite::query()->count())->toBe(0)
        ->and(ReferralReward::query()->count())->toBe(0);
});

/** @return array{ReferralInvite, User, User, string} */
function qualificationInviteFixture(string $prefix): array
{
    $referrer = User::factory()->create();
    $invitee = User::factory()->create();
    [$rewardProgram, $version] = RewardLedgerFixtures::seedVersionedProgram(
        $prefix.'-points',
        RewardProgramMode::Points,
        [
            'components' => [['type' => 'points', 'points' => 17, 'unit' => 'pts']],
            'eligibility' => ['outcomes' => ['referral_qualified']],
        ],
    );
    TransitionRewardProgramStatusAction::run($rewardProgram, RewardProgramStatus::Ready);
    TransitionRewardProgramStatusAction::run($rewardProgram, RewardProgramStatus::Active);
    EnrollRewardMemberAction::run(
        $rewardProgram,
        'user',
        (string) $referrer->getKey(),
        $version,
        'member-portal',
        CarbonImmutable::now('UTC')->subMinute(),
    );

    $referralProgram = CreateReferralProgram::run([
        'name' => 'Qualification test '.Str::random(6),
        'qualifier_outcome' => 'first_accepted_registration_or_staff_verified_checkin',
        'status' => ReferralProgramStatus::Active,
        'invite_expiry_days' => 30,
        'referrer_reward' => ['driver' => 'reward_points', 'points' => 17, 'family_key' => $rewardProgram->family_key],
        'referee_reward' => [
            'driver' => 'voucher',
            'amount_minor' => 1000,
            'currency' => 'MYR',
            'voucher' => ['type' => 'fixed', 'value' => 1000],
        ],
    ]);
    $creation = CreateReferralInvite::run($referralProgram, $referrer, (string) $invitee->email, (string) $referrer->email);
    $invite = AcceptReferralInvite::run((string) $creation->token, $invitee, (string) $invitee->email);

    return [$invite, $referrer, $invitee, (string) $creation->token];
}

it('qualifies the first accepted registration and fulfils points plus the single-use welcome voucher exactly once', function (): void {
    Queue::fake([
        QualifyReferralFromShareOutcomeJob::class,
    ]);
    [$invite, $referrer, $invitee] = qualificationInviteFixture('referral-registration');
    $this->travel(2)->seconds();
    $registeredAt = CarbonImmutable::now('UTC')->subSecond();
    $registration = Registration::factory()->forRegistrant($invitee)->create([
        'status' => 'confirmed',
        'registered_at' => $registeredAt,
    ]);
    $sourceKey = ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey());

    app(ShareTrackingService::class)->recordOutcome(
        DawahShareOutcomeType::EventRegistration,
        $sourceKey,
        actor: $invitee,
        occurredAt: $registeredAt,
    );
    Queue::assertPushed(QualifyReferralFromShareOutcomeJob::class, fn (QualifyReferralFromShareOutcomeJob $job): bool => $job->businessKey === $sourceKey);
    (new QualifyReferralFromShareOutcomeJob($sourceKey))->handle(app(QualifyReferralFromShareOutcomeAction::class));

    $invite->refresh();
    $rewards = ReferralReward::query()->where('invite_id', $invite->getKey())->orderBy('side')->get();
    $pointsReward = $rewards->firstWhere('side', ReferralRewardSide::Referrer);
    $voucherReward = $rewards->firstWhere('side', ReferralRewardSide::Referee);
    $pointsAward = RewardAward::query()
        ->where('source_business_key', 'referrals:qualified:'.$invite->getKey().':referrer')
        ->where('recipient_id', (string) $referrer->getKey())
        ->firstOrFail();
    $voucher = Voucher::query()->where('code', $voucherReward?->external_reference)->firstOrFail();

    expect($invite->status)->toBe(ReferralInviteStatus::Rewarded)
        ->and($invite->qualification_business_key)->toBe($sourceKey)
        ->and($rewards)->toHaveCount(2)
        ->and($pointsReward?->status)->toBe(ReferralRewardStatus::Fulfilled)
        ->and($voucherReward?->status)->toBe(ReferralRewardStatus::Fulfilled)
        ->and(data_get($pointsAward->amount_snapshot, 'points'))->toBe(17)
        ->and($pointsAward->beneficiary_role)->toBe(RewardBeneficiaryRole::Sharer)
        ->and($pointsAward->recipient_id)->toBe((string) $referrer->getKey())
        ->and($voucher->usage_limit)->toBe(1)
        ->and($voucher->usage_limit_per_user)->toBe(1)
        ->and(data_get($voucher->metadata, 'assignee.type'))->toBe($invitee->getMorphClass())
        ->and((string) data_get($voucher->metadata, 'assignee.id'))->toBe((string) $invitee->getKey());

    $checkin = EventCheckin::factory()->create([
        'attendee_type' => $invitee->getMorphClass(),
        'attendee_id' => $invitee->getKey(),
        'verified_by_user_id' => User::factory()->create()->getKey(),
        'checked_in_at' => CarbonImmutable::now('UTC')->addMinutes(2),
        'attendance_type' => 'check_in',
    ]);
    $checkinKey = ShareOutcomeBusinessKey::eventCheckin((string) $checkin->getKey());
    app(ShareTrackingService::class)->recordOutcome(
        DawahShareOutcomeType::EventCheckin,
        $checkinKey,
        actor: $invitee,
        occurredAt: $checkin->checked_in_at,
    );
    (new QualifyReferralFromShareOutcomeJob($checkinKey))->handle(app(QualifyReferralFromShareOutcomeAction::class));

    expect(ReferralReward::query()->where('invite_id', $invite->getKey())->count())->toBe(2)
        ->and(RewardAward::query()->where('source_business_key', 'referrals:qualified:'.$invite->getKey().':referrer')->count())->toBe(1)
        ->and(Voucher::query()->where('code', $voucher->code)->count())->toBe(1);
});

it('qualifies a staff-verified check-in when no registration was recorded first', function (): void {
    Queue::fake([
        QualifyReferralFromShareOutcomeJob::class,
    ]);
    [$invite, $referrer, $invitee] = qualificationInviteFixture('referral-checkin');
    $this->travel(2)->seconds();
    $checkedInAt = CarbonImmutable::now('UTC')->subSecond();
    $checkin = EventCheckin::factory()->create([
        'attendee_type' => $invitee->getMorphClass(),
        'attendee_id' => $invitee->getKey(),
        'verified_by_user_id' => User::factory()->create()->getKey(),
        'checked_in_at' => $checkedInAt,
        'attendance_type' => 'check_in',
    ]);
    $sourceKey = ShareOutcomeBusinessKey::eventCheckin((string) $checkin->getKey());

    app(ShareTrackingService::class)->recordOutcome(
        DawahShareOutcomeType::EventCheckin,
        $sourceKey,
        actor: $invitee,
        occurredAt: $checkin->checked_in_at,
    );
    Queue::assertPushed(QualifyReferralFromShareOutcomeJob::class);
    (new QualifyReferralFromShareOutcomeJob($sourceKey))->handle(app(QualifyReferralFromShareOutcomeAction::class));

    expect($invite->refresh()->status)->toBe(ReferralInviteStatus::Rewarded)
        ->and($invite->qualification_business_key)->toBe($sourceKey)
        ->and(ReferralReward::query()->where('invite_id', $invite->getKey())->count())->toBe(2)
        ->and(RewardAward::query()
            ->where('source_business_key', 'referrals:qualified:'.$invite->getKey().':referrer')
            ->where('recipient_id', (string) $referrer->getKey())
            ->count())->toBe(1);
});

it('preserves both rewards when a reversal job arrives before the source is invalidated', function (): void {
    Queue::fake([QualifyReferralFromShareOutcomeJob::class]);
    [$invite, , $invitee] = qualificationInviteFixture('referral-live-source');
    $this->travel(2)->seconds();
    $registration = Registration::factory()->forRegistrant($invitee)->create([
        'status' => 'confirmed',
        'registered_at' => CarbonImmutable::now('UTC')->subSecond(),
    ]);
    $sourceKey = ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey());
    app(ShareTrackingService::class)->recordOutcome(
        DawahShareOutcomeType::EventRegistration,
        $sourceKey,
        actor: $invitee,
        occurredAt: $registration->registered_at,
    );
    (new QualifyReferralFromShareOutcomeJob($sourceKey))->handle(app(QualifyReferralFromShareOutcomeAction::class));

    (new ReverseReferralRewardsForShareOutcomeJob($sourceKey, 'registration-cancelled'))
        ->handle(app(ReverseReferralRewardsForShareOutcomeAction::class));

    $rewards = ReferralReward::query()->where('invite_id', $invite->getKey())->get();
    $voucherReward = $rewards->firstWhere('side', ReferralRewardSide::Referee);
    $voucher = Voucher::query()->where('code', $voucherReward?->external_reference)->firstOrFail();
    $pointsAward = RewardAward::query()
        ->where('source_business_key', 'referrals:qualified:'.$invite->getKey().':referrer')
        ->firstOrFail();

    expect($invite->refresh()->status)->toBe(ReferralInviteStatus::Rewarded);
    expect($rewards->every(fn (ReferralReward $reward): bool => $reward->status === ReferralRewardStatus::Fulfilled))->toBeTrue();
    expect($voucher->status)->not->toBeInstanceOf(Expired::class);
    expect($pointsAward->status)->not->toBe(RewardAwardStatus::Reversed);
});

it('reverses fulfilled points and voucher sides when the qualifying source is cancelled', function (): void {
    Queue::fake([
        QualifyReferralFromShareOutcomeJob::class,
        ReverseReferralRewardsForShareOutcomeJob::class,
    ]);
    [$invite, , $invitee] = qualificationInviteFixture('referral-reversal');
    $this->travel(2)->seconds();
    $registeredAt = CarbonImmutable::now('UTC')->subSecond();
    $registration = Registration::factory()->forRegistrant($invitee)->create([
        'status' => 'confirmed',
        'registered_at' => $registeredAt,
    ]);
    $sourceKey = ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey());
    app(ShareTrackingService::class)->recordOutcome(DawahShareOutcomeType::EventRegistration, $sourceKey, actor: $invitee, occurredAt: $registeredAt);
    (new QualifyReferralFromShareOutcomeJob($sourceKey))->handle(app(QualifyReferralFromShareOutcomeAction::class));

    $registration->forceFill(['status' => 'cancelled'])->save();
    ReverseShareOutcomeAction::run($sourceKey, 'registration-cancelled');
    Queue::assertPushed(ReverseReferralRewardsForShareOutcomeJob::class, fn (ReverseReferralRewardsForShareOutcomeJob $job): bool => $job->businessKey === $sourceKey);
    (new ReverseReferralRewardsForShareOutcomeJob($sourceKey, 'registration-cancelled'))->handle(app(ReverseReferralRewardsForShareOutcomeAction::class));

    $rewards = ReferralReward::query()->where('invite_id', $invite->getKey())->get();
    $voucherReward = $rewards->firstWhere('side', ReferralRewardSide::Referee);
    $voucher = Voucher::query()->where('code', $voucherReward?->external_reference)->firstOrFail();
    $pointsKey = 'referrals:qualified:'.$invite->getKey().':referrer';
    $pointsAward = RewardAward::query()->where('source_business_key', $pointsKey)->firstOrFail();

    expect($rewards)->toHaveCount(2)
        ->and($rewards->every(fn (ReferralReward $reward): bool => $reward->status === ReferralRewardStatus::Reversed))->toBeTrue()
        ->and($invite->refresh()->status)->toBe(ReferralInviteStatus::Qualified)
        ->and($voucher->status)->toBeInstanceOf(Expired::class)
        ->and($pointsAward->status)->toBe(RewardAwardStatus::Reversed)
        ->and(app(RewardSourceFactReader::class)->read($pointsKey)?->isValid)->toBeFalse();
});
