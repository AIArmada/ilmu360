<?php

use App\Actions\Rewards\EnrollRewardMemberAction;
use App\Actions\Rewards\LeaveRewardProgramAction;
use App\Actions\Rewards\PreviewRewardQualificationAction;
use App\Actions\Rewards\PublishRewardProgramVersionAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardPreviewDecision;
use App\Enums\Rewards\RewardPreviewReason;
use App\Enums\Rewards\RewardProgramStatus;
use App\Models\RewardProgramVersion;
use App\Support\Rewards\RewardPreviewFixture;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Core\Support\RewardCoreTestFixtures;

function previewHarness(array $ruleOverrides = [], string $prefix = 'core-prev'): array
{
    [$program] = RewardCoreTestFixtures::seedVersionedProgram($prefix);

    if ($ruleOverrides !== []) {
        $rules = RewardCoreTestFixtures::rules();
        $terms = RewardCoreTestFixtures::terms();
        $terms['version_label'] = 'draft-2';

        foreach ($ruleOverrides as $section => $values) {
            foreach ((array) $values as $key => $value) {
                $rules[$section][$key] = $value;
            }
        }

        $effective = CarbonImmutable::parse('2026-07-01T00:00:00Z');

        PublishRewardProgramVersionAction::run(
            $program,
            $effective,
            $rules,
            $terms,
            null,
            null,
            null,
            $effective,
        );

        $program = $program->refresh();
    }

    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    $memberId = (string) Str::uuid();
    $version = RewardProgramVersion::query()->where('program_id', $program->getKey())->orderByDesc('version')->first();

    $enrollment = EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-07-05T00:00:00Z')
    );

    return [$program->refresh(), $memberId, $enrollment->refresh()];
}

function previewFixture(array $overrides = []): array
{
    return array_merge([
        'source_business_key' => 'share-outcome:event_checkin:evt-1',
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

it('approves the happy path with fixture-labeled deterministic output', function () {
    [$program, $memberId] = previewHarness();

    $action = app(PreviewRewardQualificationAction::class);
    $decision = CarbonImmutable::parse('2026-07-11T00:00:00Z');

    $first = $action->handle($program, RewardComponent::Points, previewFixture(), $decision, [
        'member_type' => 'user', 'member_id' => $memberId,
    ]);
    $second = $action->handle($program, RewardComponent::Points, previewFixture(), $decision, [
        'member_type' => 'user', 'member_id' => $memberId,
    ]);

    expect($first->decision)->toBe(RewardPreviewDecision::Eligible)
        ->and($first->reasons)->toBe([RewardPreviewReason::Ok, RewardPreviewReason::LiveChecksDeferred])
        ->and($first->evidenceLabel)->toBe(RewardPreviewFixture::FIXTURE_LABEL)
        ->and($first->explanations[0])->toContain('FIXTURE-NOT-SOURCE')
        ->and($second->reasons)->toBe($first->reasons)
        ->and($second->explanations)->toBe($first->explanations);
});

it('rejects non-active programs and missing versions', function () {
    [$program] = RewardCoreTestFixtures::seedVersionedProgram('core-prev-draft');

    $result = PreviewRewardQualificationAction::run(
        $program,
        RewardComponent::Points,
        previewFixture(),
        CarbonImmutable::parse('2026-07-11T00:00:00Z'),
    );

    expect($result->decision)->toBe(RewardPreviewDecision::Ineligible)
        ->and($result->reasons)->toBe([RewardPreviewReason::ProgramNotActive]);

    [$active, $windowMemberId] = previewHarness([], 'core-prev-window');

    $missing = PreviewRewardQualificationAction::run(
        $active,
        RewardComponent::Points,
        previewFixture(['occurred_at' => '2026-05-01T00:00:00Z']),
        CarbonImmutable::parse('2026-05-15T00:00:00Z'),
    );

    expect($missing->reasons)->toBe([RewardPreviewReason::VersionMissing]);

    $early = PreviewRewardQualificationAction::run(
        $active,
        RewardComponent::Points,
        previewFixture(['occurred_at' => '2026-01-01T00:00:00Z']),
        CarbonImmutable::parse('2026-07-11T00:00:00Z'),
        ['member_type' => 'user', 'member_id' => $windowMemberId],
    );

    expect($early->reasons)->toBe([RewardPreviewReason::OutsidePreclaimLookback]);
});

it('rejects disallowed outcomes, categories, and weak verification', function () {
    [$program, $memberId] = previewHarness(['eligibility' => ['categories' => ['family-night']]], 'core-prev-allow');
    $member = ['member_type' => 'user', 'member_id' => $memberId];
    $decision = CarbonImmutable::parse('2026-07-11T00:00:00Z');

    $outcome = PreviewRewardQualificationAction::run($program, RewardComponent::Points, previewFixture(['outcome' => 'person_follow']), $decision, $member);
    expect($outcome->reasons)->toBe([RewardPreviewReason::OutcomeNotAllowlisted]);

    $category = PreviewRewardQualificationAction::run($program, RewardComponent::Points, previewFixture(['category' => 'other']), $decision, $member);
    expect($category->reasons)->toBe([RewardPreviewReason::CategoryNotAllowlisted]);

    $verification = PreviewRewardQualificationAction::run($program, RewardComponent::Points, previewFixture(['category' => 'family-night', 'verification' => 'unverified']), $decision, $member);
    expect($verification->reasons)->toBe([RewardPreviewReason::VerificationInsufficient]);
});

it('never treats accepted registration as staff-verified attendance', function () {
    [$program, $memberId] = previewHarness(['eligibility' => ['min_verification' => 'staff_verified']], 'core-prev-staff');
    $member = ['member_type' => 'user', 'member_id' => $memberId];
    $decision = CarbonImmutable::parse('2026-07-11T00:00:00Z');

    $accepted = PreviewRewardQualificationAction::run($program, RewardComponent::Points, previewFixture(['verification' => 'accepted']), $decision, $member);
    expect($accepted->reasons)->toBe([RewardPreviewReason::VerificationInsufficient]);

    $verified = PreviewRewardQualificationAction::run($program, RewardComponent::Points, previewFixture(['verification' => 'staff_verified']), $decision, $member);
    expect($verified->decision)->toBe(RewardPreviewDecision::Eligible);
});

it('excludes self credit, unclaimed guests, and recurring evidence when first-only', function () {
    [$program, $memberId] = previewHarness(['eligibility' => ['first_only' => true]], 'core-prev-self');
    $member = ['member_type' => 'user', 'member_id' => $memberId];
    $decision = CarbonImmutable::parse('2026-07-11T00:00:00Z');

    $recurring = PreviewRewardQualificationAction::run($program, RewardComponent::Points, previewFixture(['is_first_time' => false]), $decision, $member);
    expect($recurring->reasons)->toBe([RewardPreviewReason::RecurringExcluded]);

    $self = PreviewRewardQualificationAction::run($program, RewardComponent::Points, previewFixture(['actor_id' => 'same', 'sharer_id' => 'same']), $decision, $member);
    expect($self->reasons)->toBe([RewardPreviewReason::SelfCreditExcluded]);

    $guest = PreviewRewardQualificationAction::run($program, RewardComponent::Points, previewFixture(['is_claimed_identity' => false]), $decision, $member);
    expect($guest->reasons)->toBe([RewardPreviewReason::GuestUnclaimed]);
});

it('requires active enrollment with current acceptance at decision time', function () {
    [$program, $memberId, $enrollment] = previewHarness([], 'core-prev-enroll');
    $decision = CarbonImmutable::parse('2026-07-11T00:00:00Z');

    $missing = PreviewRewardQualificationAction::run($program, RewardComponent::Points, previewFixture(), $decision, [
        'member_type' => 'user', 'member_id' => (string) Str::uuid(),
    ]);
    expect($missing->reasons)->toBe([RewardPreviewReason::EnrollmentMissing]);

    LeaveRewardProgramAction::run($enrollment, 'bye', CarbonImmutable::parse('2026-07-06T00:00:00Z'));

    $left = PreviewRewardQualificationAction::run($program, RewardComponent::Points, previewFixture(), $decision, [
        'member_type' => 'user', 'member_id' => $memberId,
    ]);
    expect($left->reasons)->toBe([RewardPreviewReason::EnrollmentLeft]);
});

it('blocks new qualification after material terms change until reacceptance', function () {
    [$program, $memberId] = previewHarness([], 'core-prev-mat');
    $decision = CarbonImmutable::parse('2026-08-11T00:00:00Z');
    $member = ['member_type' => 'user', 'member_id' => $memberId];

    $terms = RewardCoreTestFixtures::terms();
    $terms['version_label'] = 'draft-2';
    $terms['material']['earn_basis'] = 'Material change blocks new earning.';

    $effective = CarbonImmutable::parse('2026-08-01T00:00:00Z');

    PublishRewardProgramVersionAction::run(
        $program,
        $effective,
        RewardCoreTestFixtures::rules(),
        $terms,
        null,
        null,
        null,
        $effective,
    );

    $stale = PreviewRewardQualificationAction::run(
        $program, RewardComponent::Points,
        previewFixture(['occurred_at' => '2026-08-10T00:00:00Z']),
        $decision, $member,
    );
    expect($stale->reasons)->toBe([RewardPreviewReason::AcceptanceStaleMaterialChange]);

    $version = RewardProgramVersion::query()->where('program_id', $program->refresh()->getKey())->orderByDesc('version')->first();
    EnrollRewardMemberAction::run($program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-08-05T00:00:00Z'));

    $fresh = PreviewRewardQualificationAction::run(
        $program, RewardComponent::Points,
        previewFixture(['occurred_at' => '2026-08-10T00:00:00Z']),
        $decision, $member,
    );
    expect($fresh->decision)->toBe(RewardPreviewDecision::Eligible);
});

it('applies the published preclaim lookback without historical replay', function () {
    [$program, $memberId] = previewHarness([], 'core-prev-preclaim');
    $member = ['member_type' => 'user', 'member_id' => $memberId];

    $inside = PreviewRewardQualificationAction::run(
        $program, RewardComponent::Points,
        previewFixture(['occurred_at' => '2026-06-20T00:00:00Z']),
        CarbonImmutable::parse('2026-07-11T00:00:00Z'), $member,
    );
    expect($inside->decision)->toBe(RewardPreviewDecision::Eligible);

    $outside = PreviewRewardQualificationAction::run(
        $program, RewardComponent::Points,
        previewFixture(['occurred_at' => '2026-06-01T00:00:00Z']),
        CarbonImmutable::parse('2026-07-11T00:00:00Z'), $member,
    );
    expect($outside->reasons)->toBe([RewardPreviewReason::OutsidePreclaimLookback]);

    $unattributed = PreviewRewardQualificationAction::run(
        $program, RewardComponent::Points,
        previewFixture(['occurred_at' => '2026-06-20T00:00:00Z', 'is_attributed' => false]),
        CarbonImmutable::parse('2026-07-11T00:00:00Z'), $member,
    );
    expect($unattributed->reasons)->toBe([RewardPreviewReason::LateAttributionRefused]);

    $future = PreviewRewardQualificationAction::run(
        $program, RewardComponent::Points,
        previewFixture(['occurred_at' => '2026-07-12T00:00:00Z']),
        CarbonImmutable::parse('2026-07-11T00:00:00Z'), $member,
    );
    expect($future->reasons)->toBe([RewardPreviewReason::EvidenceAfterDecision]);
});

it('holds ambiguous evidence and rejects stacking conflicts', function () {
    [$program, $memberId] = previewHarness([], 'core-prev-hold');
    $member = ['member_type' => 'user', 'member_id' => $memberId];
    $decision = CarbonImmutable::parse('2026-07-11T00:00:00Z');

    $hold = PreviewRewardQualificationAction::run($program, RewardComponent::Points, previewFixture(['is_ambiguous_device' => true]), $decision, $member);
    expect($hold->decision)->toBe(RewardPreviewDecision::Hold)
        ->and($hold->reasons)->toBe([RewardPreviewReason::HoldRequired]);

    $stack = PreviewRewardQualificationAction::run($program, RewardComponent::Points, previewFixture(['claimed_family_keys' => ['other-family']]), $decision, $member);
    expect($stack->reasons)->toBe([RewardPreviewReason::StackingConflict]);

    $component = PreviewRewardQualificationAction::run($program, RewardComponent::Cash, previewFixture(), $decision, $member);
    expect($component->reasons)->toBe([RewardPreviewReason::ComponentNotEnabled]);
});

it('rejects malformed fixture input without guessing', function () {
    [$program] = previewHarness([], 'core-prev-bad');

    expect(fn () => PreviewRewardQualificationAction::run($program, RewardComponent::Points, ['outcome' => 'event_checkin'], CarbonImmutable::parse('2026-07-11T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'source_business_key');

    expect(fn () => PreviewRewardQualificationAction::run($program, RewardComponent::Points, previewFixture(['outcome' => 'nope']), CarbonImmutable::parse('2026-07-11T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'allowlisted');
});
