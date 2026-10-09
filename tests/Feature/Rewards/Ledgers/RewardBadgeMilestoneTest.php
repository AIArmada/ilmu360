<?php

use App\Actions\Rewards\GrantRewardBadgeAction;
use App\Actions\Rewards\InvalidateRewardMilestoneContributionAction;
use App\Actions\Rewards\RecordRewardMilestoneContributionAction;
use App\Actions\Rewards\RevokeRewardBadgeAction;
use App\Actions\Rewards\TransitionRewardAwardAction;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardBadgeStatus;
use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardEligibleOutcome;
use App\Enums\Rewards\RewardMilestoneStatus;
use App\Enums\Rewards\RewardProgramMode;
use App\Models\RewardAward;
use App\Models\RewardBadgeGrant;
use App\Models\RewardMilestoneContribution;
use App\Models\RewardPointLot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Ledgers\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Ledgers\Support\RewardLedgerFixtures;

function milestoneProgram(string $prefix, string $key, int $target, int $window, array $reward, RewardProgramMode $mode = RewardProgramMode::Points): array
{
    $milestones = [['key' => $key, 'target_count' => $target, 'window_days' => $window, 'reward' => $reward]];

    [$program, $version, $enrollment, $memberId] = RewardLedgerFixtures::seedActiveEnrolled(
        $prefix, $mode, ['milestones' => $milestones],
    );

    $reader = new FakeRewardSourceFactReader;

    return [$program, $memberId, $reader];
}

function milestoneRecord($reader, string $recipient, string $family, string $key, int $target, int $window, $component, array $reward, string $source, string $occurred, string $programId): array
{
    $reader->seed(RewardLedgerFixtures::fact($source, $recipient, $occurred));

    return (new RecordRewardMilestoneContributionAction($reader))->handle(
        'user', $recipient, $family, $key, $target, $window,
        $component, $reward,
        $source, 'user', $recipient, RewardBeneficiaryRole::Participant,
        RewardEligibleOutcome::EventCheckin, CarbonImmutable::parse($occurred),
        $programId,
    );
}

it('grants badges idempotently and revokes with retained history', function () {
    $recipient = (string) Str::uuid();
    $family = 'badge-fam-'.Str::lower(Str::random(4));
    $staff = RewardLedgerFixtures::staffId();

    $first = GrantRewardBadgeAction::run('user', $recipient, $family, 'first-share', null, null, 'user', $staff, 'staff', 'earned', null, CarbonImmutable::parse('2026-06-15T00:00:00Z'));
    $replay = GrantRewardBadgeAction::run('user', $recipient, $family, 'first-share', null, null, 'user', $staff, 'staff', 'earned', null, CarbonImmutable::parse('2026-06-15T00:00:00Z'));

    expect((string) $replay->getKey())->toBe((string) $first->getKey())
        ->and(RewardBadgeGrant::query()->count())->toBe(1);

    $revoked = RevokeRewardBadgeAction::run('user', $recipient, $family, 'first-share', 'user', $staff, 'staff', 'source-error', CarbonImmutable::parse('2026-06-16T00:00:00Z'));

    expect($revoked->status)->toBe(RewardBadgeStatus::Revoked)
        ->and($revoked->revoked_at)->not->toBeNull();

    $noop = RevokeRewardBadgeAction::run('user', $recipient, $family, 'first-share', 'user', $staff, 'staff', 'again', CarbonImmutable::parse('2026-06-17T00:00:00Z'));

    expect($noop)->toBeNull();

    $regrant = GrantRewardBadgeAction::run('user', $recipient, $family, 'first-share', null, null, 'user', $staff, 'staff', 're-earned', null, CarbonImmutable::parse('2026-06-18T00:00:00Z'));

    expect($regrant->seq)->toBe(2)
        ->and($regrant->status)->toBe(RewardBadgeStatus::Granted)
        ->and(RewardBadgeGrant::query()->count())->toBe(2);

    expect(fn () => RevokeRewardBadgeAction::run('user', $recipient, $family, 'first-share', 'user', $recipient, 'member', 'self-revoke', CarbonImmutable::parse('2026-06-19T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'staff/admin/system');
});

it('records contributions into deterministic cycles and achieves exactly once', function () {
    [$program, $recipient, $reader] = milestoneProgram('ledger-mile', 'three-checkins', 3, 30, ['type' => 'points', 'points' => 25, 'unit' => 'pts']);
    $family = $program->family_key;

    $record = fn (string $source, string $occurred): array => milestoneRecord(
        $reader, $recipient, $family, 'three-checkins', 3, 30,
        RewardComponent::Points, ['points' => 25, 'unit' => 'pts'],
        $source, $occurred, (string) $program->getKey(),
    );

    $one = $record('src-mile-1', '2026-06-10T00:00:00Z');
    $two = $record('src-mile-2', '2026-06-15T00:00:00Z');

    expect($one['achieved'])->toBeFalse()
        ->and($two['achieved'])->toBeFalse()
        ->and((string) $one['cycle']->getKey())->toBe((string) $two['cycle']->getKey())
        ->and($two['cycle']->current_count)->toBe(2);

    $duplicate = $record('src-mile-1', '2026-06-10T00:00:00Z');

    expect($duplicate['achieved'])->toBeFalse()
        ->and((string) $duplicate['contribution']->getKey())->toBe((string) $one['contribution']->getKey())
        ->and(RewardMilestoneContribution::query()->count())->toBe(2);

    $three = $record('src-mile-3', '2026-06-20T00:00:00Z');

    expect($three['achieved'])->toBeTrue()
        ->and($three['cycle']->status)->toBe(RewardMilestoneStatus::Achieved)
        ->and($three['cycle']->award_id)->not->toBeNull();

    $award = RewardAward::query()->whereKey($three['cycle']->award_id)->firstOrFail();

    expect($award->status)->toBe(RewardAwardStatus::Approved)
        ->and($award->amount_snapshot)->toBe(['type' => 'points', 'points' => 25, 'unit' => 'pts'])
        ->and($award->policy_version_id)->not->toBeNull()
        ->and($award->enrollment_id)->not->toBeNull()
        ->and($award->acceptance_id)->not->toBeNull()
        ->and($award->source_business_key)->toBe('milestone:'.$family.':three-checkins:'.$three['cycle']->window_start->format('Y-m-d'));

    $four = $record('src-mile-4', '2026-06-25T00:00:00Z');

    expect($four['achieved'])->toBeFalse()
        ->and(RewardAward::query()->count())->toBe(1);
});

it('splits fixed windows deterministically and rejects definition mismatches', function () {
    [$program, $recipient, $reader] = milestoneProgram('ledger-milew', 'monthly-three', 3, 30, ['type' => 'points', 'points' => 25, 'unit' => 'pts']);
    $family = $program->family_key;

    $june = milestoneRecord(
        $reader, $recipient, $family, 'monthly-three', 3, 30,
        RewardComponent::Points, ['points' => 25, 'unit' => 'pts'],
        'src-milew-1', '2026-06-10T00:00:00Z', (string) $program->getKey(),
    );

    $july = milestoneRecord(
        $reader, $recipient, $family, 'monthly-three', 3, 30,
        RewardComponent::Points, ['points' => 25, 'unit' => 'pts'],
        'src-milew-2', '2026-07-20T00:00:00Z', (string) $program->getKey(),
    );

    expect((string) $june['cycle']->getKey())->not->toBe((string) $july['cycle']->getKey())
        ->and($june['cycle']->window_start->toIso8601String())->not->toBe($july['cycle']->window_start->toIso8601String());

    $reader->seed(RewardLedgerFixtures::fact('src-milew-3', $recipient, '2026-06-12T00:00:00Z'));

    expect(fn () => (new RecordRewardMilestoneContributionAction($reader))->handle(
        'user', $recipient, $family, 'monthly-three', 5, 30,
        RewardComponent::Points, ['points' => 25, 'unit' => 'pts'],
        'src-milew-3', 'user', $recipient, RewardBeneficiaryRole::Participant,
        RewardEligibleOutcome::EventCheckin, CarbonImmutable::parse('2026-06-12T00:00:00Z'),
        (string) $program->getKey(),
    ))->toThrow(InvalidArgumentException::class, 'definition mismatches');

    expect(fn () => RecordRewardMilestoneContributionAction::run(
        'user', $recipient, $family, 'cash-mile', 2, 30,
        RewardComponent::Cash, ['minor_units' => 100, 'currency' => 'MYR'],
        'src-milew-4', 'user', $recipient, RewardBeneficiaryRole::Participant,
        RewardEligibleOutcome::EventCheckin, CarbonImmutable::parse('2026-06-12T00:00:00Z'),
        (string) $program->getKey(),
    ))->toThrow(InvalidArgumentException::class, 'WP6 finance scope');
});

it('revokes contributor corrections consistently, reversing grants and retaining history', function () {
    [$program, $recipient, $reader] = milestoneProgram('ledger-milei', 'two-saves', 2, 30, ['type' => 'badge', 'badge_key' => 'steady-saver'], RewardProgramMode::Badges);
    $family = $program->family_key;

    $sources = ['src-milei-1', 'src-milei-2'];
    $last = null;

    foreach ($sources as $index => $source) {
        $last = milestoneRecord(
            $reader, $recipient, $family, 'two-saves', 2, 30,
            RewardComponent::Badge, ['badge_key' => 'steady-saver'],
            $source, '2026-06-'.($index + 10).'T00:00:00Z', (string) $program->getKey(),
        );
    }

    expect($last['achieved'])->toBeTrue();

    $award = RewardAward::query()->whereKey($last['cycle']->award_id)->firstOrFail();
    $staff = RewardLedgerFixtures::staffId();

    (new TransitionRewardAwardAction(null, $reader))->handle($award, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'grant', null, null, CarbonImmutable::parse('2026-06-12T00:00:00Z'));

    expect(RewardBadgeGrant::query()->where('award_id', $award->getKey())->where('status', RewardBadgeStatus::Granted)->count())->toBe(1);

    $contribution = RewardMilestoneContribution::query()->where('source_business_key', 'src-milei-1')->firstOrFail();

    $cycle = InvalidateRewardMilestoneContributionAction::run($contribution, 'user', $staff, 'staff', 'source-corrected', CarbonImmutable::parse('2026-06-13T00:00:00Z'));

    expect($cycle->status)->toBe(RewardMilestoneStatus::Invalidated)
        ->and($cycle->current_count)->toBe(1)
        ->and($award->refresh()->status)->toBe(RewardAwardStatus::Reversed);

    expect(RewardBadgeGrant::query()->where('award_id', $award->getKey())->where('status', RewardBadgeStatus::Revoked)->count())->toBe(1)
        ->and(RewardMilestoneContribution::query()->count())->toBe(2)
        ->and($contribution->refresh()->revoked_at)->not->toBeNull();

    $replay = InvalidateRewardMilestoneContributionAction::run($contribution, 'user', $staff, 'staff', 'source-corrected', CarbonImmutable::parse('2026-06-13T00:00:00Z'));

    expect($replay->current_count)->toBe(1)
        ->and($award->refresh()->entries()->count())->toBe($award->entries()->count());
});

it('keeps achieved milestones when corrections stay above target', function () {
    [$program, $recipient, $reader] = milestoneProgram('ledger-milea', 'two-plus', 2, 30, ['type' => 'points', 'points' => 25, 'unit' => 'pts']);
    $family = $program->family_key;

    foreach (['src-milea-1', 'src-milea-2', 'src-milea-3'] as $index => $source) {
        milestoneRecord(
            $reader, $recipient, $family, 'two-plus', 2, 30,
            RewardComponent::Points, ['points' => 25, 'unit' => 'pts'],
            $source, '2026-06-'.($index + 10).'T00:00:00Z', (string) $program->getKey(),
        );
    }

    $staff = RewardLedgerFixtures::staffId();
    $first = RewardMilestoneContribution::query()->where('source_business_key', 'src-milea-1')->firstOrFail();

    $cycle = InvalidateRewardMilestoneContributionAction::run($first, 'user', $staff, 'staff', 'one-bad-source', CarbonImmutable::parse('2026-06-13T00:00:00Z'));

    expect($cycle->status)->toBe(RewardMilestoneStatus::Achieved)
        ->and($cycle->current_count)->toBe(2)
        ->and(RewardPointLot::query()->count())->toBe(0);
});
