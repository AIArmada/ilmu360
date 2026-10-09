<?php

use App\Actions\Rewards\EnrollRewardMemberAction;
use App\Actions\Rewards\PreviewRewardQualificationAction;
use App\Actions\Rewards\PublishRewardProgramVersionAction;
use App\Actions\Rewards\ReacceptRewardTermsAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardPreviewDecision;
use App\Enums\Rewards\RewardPreviewReason;
use App\Enums\Rewards\RewardProgramStatus;
use App\Models\RewardEnrollment;
use App\Models\RewardProgramVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Core\Support\RewardCoreTestFixtures;

function c2Fixture(array $overrides = []): array
{
    return array_merge([
        'source_business_key' => 'share-outcome:event_checkin:c2-1',
        'outcome' => 'event_checkin',
        'category' => null,
        'occurred_at' => '2026-07-24T00:00:00Z',
        'verification' => 'accepted',
        'is_first_time' => true,
        'actor_id' => 'actor-1',
        'sharer_id' => 'sharer-1',
        'is_claimed_identity' => true,
        'is_attributed' => true,
    ], $overrides);
}

function c2ActiveProgram(string $prefix): array
{
    [$program, $v1] = RewardCoreTestFixtures::seedVersionedProgram($prefix);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    return [$program->refresh(), $v1];
}

it('keeps v1 consent valid after advance v2 acceptance before the effective boundary', function () {
    [$program] = c2ActiveProgram('core-c2-advance');

    $memberId = (string) Str::uuid();
    $v1 = RewardProgramVersion::query()->where('program_id', $program->getKey())->orderBy('version')->first();
    EnrollRewardMemberAction::run($program, 'user', $memberId, $v1, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'));

    $terms2 = RewardCoreTestFixtures::terms();
    $terms2['version_label'] = 'draft-2';
    $terms2['material']['earn_basis'] = 'Advance v2 basis.';

    $v2 = PublishRewardProgramVersionAction::run(
        $program,
        CarbonImmutable::parse('2026-08-01T00:00:00Z'),
        RewardCoreTestFixtures::rules(),
        $terms2,
        null,
        null,
        null,
        CarbonImmutable::parse('2026-07-15T00:00:00Z'),
    );

    ReacceptRewardTermsAction::run(
        RewardEnrollment::query()->where('member_id', $memberId)->first(),
        $v2,
        'member-portal',
        CarbonImmutable::parse('2026-07-20T00:00:00Z'),
    );

    $member = ['member_type' => 'user', 'member_id' => $memberId];

    $v1Decision = PreviewRewardQualificationAction::run(
        $program, RewardComponent::Points, c2Fixture(), CarbonImmutable::parse('2026-07-25T00:00:00Z'), $member,
    );

    expect($v1Decision->decision)->toBe(RewardPreviewDecision::Eligible);

    $v2Decision = PreviewRewardQualificationAction::run(
        $program, RewardComponent::Points,
        c2Fixture(['source_business_key' => 'share-outcome:event_checkin:c2-2', 'occurred_at' => '2026-08-04T00:00:00Z']),
        CarbonImmutable::parse('2026-08-05T00:00:00Z'), $member,
    );

    expect($v2Decision->decision)->toBe(RewardPreviewDecision::Eligible);
});

it('applies decision-time policy with lookback for pre-claim sources across a material switch', function () {
    [$program] = c2ActiveProgram('core-c2-switch');

    $memberId = (string) Str::uuid();
    $v1 = RewardProgramVersion::query()->where('program_id', $program->getKey())->orderBy('version')->first();

    $terms2 = RewardCoreTestFixtures::terms();
    $terms2['version_label'] = 'draft-2';
    $terms2['material']['earn_basis'] = 'Switch basis.';

    $effective = CarbonImmutable::parse('2026-07-01T00:00:00Z');
    $v2 = PublishRewardProgramVersionAction::run($program, $effective, RewardCoreTestFixtures::rules(), $terms2, null, null, null, $effective);

    EnrollRewardMemberAction::run($program, 'user', $memberId, $v2, 'member-portal', CarbonImmutable::parse('2026-07-05T00:00:00Z'));
    $member = ['member_type' => 'user', 'member_id' => $memberId];

    $inside = PreviewRewardQualificationAction::run(
        $program, RewardComponent::Points,
        c2Fixture(['source_business_key' => 'share-outcome:event_checkin:c2-3', 'occurred_at' => '2026-06-20T00:00:00Z']),
        CarbonImmutable::parse('2026-07-11T00:00:00Z'), $member,
    );

    expect($inside->decision)->toBe(RewardPreviewDecision::Eligible);

    $outside = PreviewRewardQualificationAction::run(
        $program, RewardComponent::Points,
        c2Fixture(['source_business_key' => 'share-outcome:event_checkin:c2-4', 'occurred_at' => '2026-05-01T00:00:00Z']),
        CarbonImmutable::parse('2026-07-11T00:00:00Z'), $member,
    );

    expect($outside->reasons)->toBe([RewardPreviewReason::OutsidePreclaimLookback]);
});

it('resolves exact effective boundaries by decision time', function () {
    [$program] = c2ActiveProgram('core-c2-edge');

    $memberId = (string) Str::uuid();
    $v1 = RewardProgramVersion::query()->where('program_id', $program->getKey())->orderBy('version')->first();
    EnrollRewardMemberAction::run($program, 'user', $memberId, $v1, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'));

    $terms2 = RewardCoreTestFixtures::terms();
    $terms2['version_label'] = 'draft-2';
    $terms2['material']['earn_basis'] = 'Boundary basis.';
    $effective = CarbonImmutable::parse('2026-08-01T00:00:00Z');
    $v2 = PublishRewardProgramVersionAction::run($program, $effective, RewardCoreTestFixtures::rules(), $terms2, null, null, null, $effective);

    $member = ['member_type' => 'user', 'member_id' => $memberId];

    $before = PreviewRewardQualificationAction::run(
        $program, RewardComponent::Points,
        c2Fixture(['occurred_at' => '2026-07-31T12:00:00Z']),
        CarbonImmutable::parse('2026-07-31T23:59:59Z'), $member,
    );

    expect($before->decision)->toBe(RewardPreviewDecision::Eligible)
        ->and($before->applicableVersionId)->toBe((string) $v1->getKey());

    $at = PreviewRewardQualificationAction::run(
        $program, RewardComponent::Points,
        c2Fixture(['occurred_at' => '2026-08-01T00:00:00Z']),
        CarbonImmutable::parse('2026-08-01T00:00:00Z'), $member,
    );

    expect($at->reasons)->toBe([RewardPreviewReason::AcceptanceStaleMaterialChange])
        ->and($at->applicableVersionId)->toBe((string) $v2->getKey());
});

it('rejects retroactive publication and backward publication time', function () {
    [$program] = RewardCoreTestFixtures::seedVersionedProgram('core-c2-retro');

    expect(fn () => PublishRewardProgramVersionAction::run(
        $program,
        CarbonImmutable::parse('2026-06-15T00:00:00Z'),
        RewardCoreTestFixtures::rules(),
        RewardCoreTestFixtures::terms(),
        null, null, null,
        CarbonImmutable::parse('2026-08-01T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'must not be before its own publication');

    expect(fn () => PublishRewardProgramVersionAction::run(
        $program,
        CarbonImmutable::parse('2026-07-01T00:00:00Z'),
        RewardCoreTestFixtures::rules(),
        RewardCoreTestFixtures::terms(),
        null, null, null,
        CarbonImmutable::parse('2026-05-15T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'cannot move backwards');
});
