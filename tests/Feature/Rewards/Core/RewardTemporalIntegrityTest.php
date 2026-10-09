<?php

use App\Actions\Rewards\EnrollRewardMemberAction;
use App\Actions\Rewards\LeaveRewardProgramAction;
use App\Actions\Rewards\PreviewRewardQualificationAction;
use App\Actions\Rewards\ReacceptRewardTermsAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardPreviewDecision;
use App\Enums\Rewards\RewardPreviewReason;
use App\Enums\Rewards\RewardProgramStatus;
use App\Models\RewardEnrollmentPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Core\Support\RewardCoreTestFixtures;

it('rejects leave before join and consent before publication', function () {
    [$program, $version] = RewardCoreTestFixtures::seedVersionedProgram('core-c3-time');

    $enrollment = EnrollRewardMemberAction::run(
        $program, 'user', (string) Str::uuid(), $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z')
    );

    expect(fn () => LeaveRewardProgramAction::run($enrollment, 'backdated', CarbonImmutable::parse('2026-06-05T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'cannot predate the period join');

    expect(fn () => EnrollRewardMemberAction::run(
        $program, 'user', (string) Str::uuid(), $version, 'member-portal', CarbonImmutable::parse('2026-05-15T00:00:00Z')
    ))->toThrow(InvalidArgumentException::class, 'cannot predate the policy publication');

    expect(fn () => ReacceptRewardTermsAction::run(
        $enrollment, $version, 'member-portal', CarbonImmutable::parse('2026-05-15T00:00:00Z')
    ))->toThrow(InvalidArgumentException::class, 'cannot predate the policy publication');
});

it('rejects backward acceptance and overlapping rejoin', function () {
    [$program, $version] = RewardCoreTestFixtures::seedVersionedProgram('core-c3-mono');

    $memberId = (string) Str::uuid();
    $enrollment = EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z')
    );
    ReacceptRewardTermsAction::run(
        $enrollment, $version, 'member-portal', CarbonImmutable::parse('2026-06-12T00:00:00Z')
    );
    LeaveRewardProgramAction::run($enrollment->refresh(), 'bye', CarbonImmutable::parse('2026-06-20T00:00:00Z'));

    expect(fn () => ReacceptRewardTermsAction::run(
        $enrollment->refresh(), $version, 'member-portal', CarbonImmutable::parse('2026-06-11T00:00:00Z')
    ))->toThrow(InvalidArgumentException::class, 'cannot move backwards');

    expect(fn () => ReacceptRewardTermsAction::run(
        $enrollment->refresh(), $version, 'member-portal', CarbonImmutable::parse('2026-06-05T00:00:00Z')
    ))->toThrow(InvalidArgumentException::class, 'cannot predate the enrollment');

    expect(fn () => EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-15T00:00:00Z')
    ))->toThrow(InvalidArgumentException::class, 'strictly after the last leave');

    expect(fn () => EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-20T00:00:00Z')
    ))->toThrow(InvalidArgumentException::class, 'strictly after the last leave');
});

it('gates gap facts by the relevant period and published lookback', function () {
    [$program, $version] = RewardCoreTestFixtures::seedVersionedProgram('core-c3-gap');
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    $memberId = (string) Str::uuid();
    $enrollment = EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z')
    );
    LeaveRewardProgramAction::run($enrollment, 'gap', CarbonImmutable::parse('2026-06-15T00:00:00Z'));
    EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-25T00:00:00Z')
    );

    $member = ['member_type' => 'user', 'member_id' => $memberId];
    $base = [
        'source_business_key' => 'share-outcome:event_checkin:c3-gap',
        'outcome' => 'event_checkin',
        'category' => null,
        'verification' => 'accepted',
        'is_first_time' => true,
        'actor_id' => 'actor-1',
        'sharer_id' => 'sharer-1',
        'is_claimed_identity' => true,
        'is_attributed' => true,
    ];

    $inside = PreviewRewardQualificationAction::run(
        $program, RewardComponent::Points,
        array_merge($base, ['occurred_at' => '2026-06-17T00:00:00Z']),
        CarbonImmutable::parse('2026-06-26T00:00:00Z'), $member,
    );

    expect($inside->decision)->toBe(RewardPreviewDecision::Eligible);

    $outside = PreviewRewardQualificationAction::run(
        $program, RewardComponent::Points,
        array_merge($base, ['occurred_at' => '2026-05-01T00:00:00Z']),
        CarbonImmutable::parse('2026-06-26T00:00:00Z'), $member,
    );

    expect($outside->reasons)->toBe([RewardPreviewReason::OutsidePreclaimLookback]);
});

it('preserves frozen history across leave and rejoin', function () {
    [$program, $version] = RewardCoreTestFixtures::seedVersionedProgram('core-c3-frozen');

    $memberId = (string) Str::uuid();
    $enrollment = EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z')
    );
    $firstHash = $enrollment->acceptances()->orderBy('seq')->first()->material_terms_hash;

    LeaveRewardProgramAction::run($enrollment, 'bye', CarbonImmutable::parse('2026-06-20T00:00:00Z'));
    $rejoined = EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-07-10T00:00:00Z')
    );

    $rows = $rejoined->acceptances()->orderBy('seq')->get();
    $periods = $rejoined->periods()->orderBy('seq')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->material_terms_hash)->toBe($firstHash)
        ->and($rows[0]->material_terms_hash)->toBe($version->material_terms_hash)
        ->and($periods)->toHaveCount(2)
        ->and($periods[0]->left_at)->not->toBeNull()
        ->and($periods[1]->left_at)->toBeNull();
});

it('rejects inverted period and enrollment timestamps at model boundaries', function () {
    [$program, $version] = RewardCoreTestFixtures::seedVersionedProgram('core-c3-model');

    $enrollment = EnrollRewardMemberAction::run(
        $program, 'user', (string) Str::uuid(), $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z')
    );
    $period = $enrollment->periods()->orderBy('seq')->first();

    expect(fn () => $period->forceFill(['left_at' => CarbonImmutable::parse('2026-06-05T00:00:00Z')])->save())
        ->toThrow(LogicException::class, 'cannot predate');

    expect(fn () => $enrollment->forceFill(['left_at' => CarbonImmutable::parse('2026-06-05T00:00:00Z')])->save())
        ->toThrow(LogicException::class, 'cannot predate');

    expect(fn () => RewardEnrollmentPeriod::query()->create([
        'enrollment_id' => $enrollment->getKey(),
        'seq' => 99,
        'joined_at' => CarbonImmutable::parse('2026-06-10T00:00:00Z'),
        'left_at' => CarbonImmutable::parse('2026-06-05T00:00:00Z'),
        'join_acceptance_id' => $enrollment->current_acceptance_id,
    ]))->toThrow(LogicException::class, 'cannot predate');
});
