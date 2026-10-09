<?php

use App\Actions\Rewards\EnrollRewardMemberAction;
use App\Actions\Rewards\PreviewRewardQualificationAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardEligibleOutcome;
use App\Enums\Rewards\RewardPreviewDecision;
use App\Enums\Rewards\RewardPreviewReason;
use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardProgramStatus;
use App\Enums\Rewards\RewardVerificationLevel;
use App\Models\RewardProgramVersion;
use App\Support\Rewards\RewardPreviewFixture;
use App\Support\Rewards\RewardRuleValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Core\Support\RewardCoreTestFixtures;

function c4Fixture(array $overrides = []): array
{
    return array_merge([
        'source_business_key' => 'share-outcome:event_checkin:c4-1',
        'outcome' => 'event_checkin',
        'category' => null,
        'occurred_at' => '2026-07-10T00:00:00Z',
        'verification' => 'accepted',
        'is_first_time' => true,
        'actor_id' => 'actor-1',
        'sharer_id' => 'sharer-1',
        'is_claimed_identity' => true,
        'is_attributed' => true,
    ], $overrides);
}

function c4Harness(string $prefix = 'core-c4'): array
{
    [$program] = RewardCoreTestFixtures::seedVersionedProgram($prefix);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    $memberId = (string) Str::uuid();
    $version = RewardProgramVersion::query()->where('program_id', $program->getKey())->orderByDesc('version')->first();
    EnrollRewardMemberAction::run($program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-07-05T00:00:00Z'));

    return [$program->refresh(), $memberId];
}

it('rejects missing, empty, relative, and malformed fixture timestamps without guessing', function () {
    [$program] = c4Harness('core-c4-date');
    $decision = CarbonImmutable::parse('2026-07-11T00:00:00Z');

    foreach ([
        array_diff_key(c4Fixture(), ['occurred_at' => true]),
        c4Fixture(['occurred_at' => '']),
        c4Fixture(['occurred_at' => 'tomorrow']),
        c4Fixture(['occurred_at' => '+1 day']),
        c4Fixture(['occurred_at' => 'next Monday']),
        c4Fixture(['occurred_at' => 'not-a-date']),
        c4Fixture(['occurred_at' => '2026-07-10']),
    ] as $index => $bad) {
        expect(fn () => PreviewRewardQualificationAction::run($program, RewardComponent::Points, $bad, $decision))
            ->toThrow(InvalidArgumentException::class, 'explicit absolute timestamp', "case {$index}");
    }
});

it('rejects disabled or omitted protection flags at validation', function () {
    $validator = new RewardRuleValidator;

    foreach (['exclude_self_credit', 'require_enrollment', 'require_claimed_identity'] as $flag) {
        $missing = RewardCoreTestFixtures::rules();
        unset($missing['eligibility'][$flag]);
        expect($validator->errors($missing, RewardProgramMode::Points, 'own-family')[0])->toContain("Eligibility [{$flag}] must be true");

        $disabled = RewardCoreTestFixtures::rules();
        $disabled['eligibility'][$flag] = false;
        expect($validator->errors($disabled, RewardProgramMode::Points, 'own-family')[0])->toContain("Eligibility [{$flag}] must be true");
    }
});

it('rejects unclaimed, unattributed, bot, cancelled, and invalid sources', function () {
    [$program, $memberId] = c4Harness('core-c4-src');
    $member = ['member_type' => 'user', 'member_id' => $memberId];
    $decision = CarbonImmutable::parse('2026-07-11T00:00:00Z');

    $guest = PreviewRewardQualificationAction::run($program, RewardComponent::Points, c4Fixture(['is_claimed_identity' => false]), $decision, $member);
    expect($guest->reasons)->toBe([RewardPreviewReason::GuestUnclaimed]);

    $unattributed = PreviewRewardQualificationAction::run($program, RewardComponent::Points, c4Fixture(['is_attributed' => false]), $decision, $member);
    expect($unattributed->reasons)->toBe([RewardPreviewReason::LateAttributionRefused]);

    $bot = PreviewRewardQualificationAction::run($program, RewardComponent::Points, c4Fixture(['is_bot' => true]), $decision, $member);
    expect($bot->reasons)->toBe([RewardPreviewReason::SourceBotExcluded]);

    $cancelled = PreviewRewardQualificationAction::run($program, RewardComponent::Points, c4Fixture(['is_cancelled' => true]), $decision, $member);
    expect($cancelled->reasons)->toBe([RewardPreviewReason::SourceCancelled]);

    $invalid = PreviewRewardQualificationAction::run($program, RewardComponent::Points, c4Fixture(['is_valid_source' => false]), $decision, $member);
    expect($invalid->reasons)->toBe([RewardPreviewReason::SourceInvalid]);

    $self = PreviewRewardQualificationAction::run($program, RewardComponent::Points, c4Fixture(['actor_id' => 'same', 'sharer_id' => 'same']), $decision, $member);
    expect($self->reasons)->toBe([RewardPreviewReason::SelfCreditExcluded]);
});

it('applies per-kind verification without letting moderation vouch for attendance', function () {
    expect(RewardVerificationLevel::ModeratedApproved->satisfiesFor(RewardVerificationLevel::StaffVerified, RewardEligibleOutcome::EventSubmission))->toBeTrue();
    expect(RewardVerificationLevel::ModeratedApproved->satisfiesFor(RewardVerificationLevel::StaffVerified, RewardEligibleOutcome::EventCheckin))->toBeFalse();
    expect(RewardVerificationLevel::ModeratedApproved->satisfiesFor(RewardVerificationLevel::Accepted, RewardEligibleOutcome::EventRegistration))->toBeFalse();
    expect(RewardVerificationLevel::StaffVerified->satisfiesFor(RewardVerificationLevel::StaffVerified, RewardEligibleOutcome::EventCheckin))->toBeTrue();

    [$program, $memberId] = c4Harness('core-c4-verify');
    $member = ['member_type' => 'user', 'member_id' => $memberId];
    $decision = CarbonImmutable::parse('2026-07-11T00:00:00Z');

    $mismatch = PreviewRewardQualificationAction::run(
        $program, RewardComponent::Points,
        c4Fixture(['outcome' => 'event_checkin', 'verification' => 'moderated_approved']),
        $decision, $member,
    );

    expect($mismatch->reasons)->toBe([RewardPreviewReason::VerificationInsufficient]);

    $submission = PreviewRewardQualificationAction::run(
        $program, RewardComponent::Points,
        c4Fixture(['source_business_key' => 'share-outcome:event_submission:c4-2', 'outcome' => 'event_submission', 'verification' => 'moderated_approved']),
        $decision, $member,
    );

    expect($submission->decision)->toBe(RewardPreviewDecision::Eligible);
});

it('validates directly constructed fixtures with the same invariants', function () {
    expect(fn () => new RewardPreviewFixture(
        sourceBusinessKey: '',
        outcome: RewardEligibleOutcome::EventCheckin,
        category: null,
        occurredAt: CarbonImmutable::parse('2026-07-10T00:00:00Z'),
        verification: RewardVerificationLevel::Accepted,
        isFirstTime: true,
        actorId: null,
        sharerId: null,
        isClaimedIdentity: true,
        isAttributed: true,
    ))->toThrow(InvalidArgumentException::class, 'source_business_key');

    expect(fn () => new RewardPreviewFixture(
        sourceBusinessKey: 'share-outcome:event_checkin:c4-3',
        outcome: RewardEligibleOutcome::EventCheckin,
        category: 'BAD CATEGORY!',
        occurredAt: CarbonImmutable::parse('2026-07-10T00:00:00Z'),
        verification: RewardVerificationLevel::Accepted,
        isFirstTime: true,
        actorId: null,
        sharerId: null,
        isClaimedIdentity: true,
        isAttributed: true,
    ))->toThrow(InvalidArgumentException::class, 'category');
});
