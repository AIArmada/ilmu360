<?php

use App\Actions\Rewards\EnrollRewardMemberAction;
use App\Actions\Rewards\LeaveRewardProgramAction;
use App\Actions\Rewards\PublishRewardProgramVersionAction;
use App\Actions\Rewards\ReacceptRewardTermsAction;
use App\Enums\Rewards\RewardEnrollmentStatus;
use App\Enums\Rewards\RewardProgramMode;
use App\Models\RewardEnrollment;
use App\Models\RewardProgram;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Core\Support\RewardCoreTestFixtures;

function seedVersionedProgram(string $prefix): array
{
    return RewardCoreTestFixtures::seedVersionedProgram($prefix);
}

it('enrolls with canonical identity plus acceptance and period rows', function () {
    [$program, $version] = seedVersionedProgram('core-enroll');

    $memberId = (string) Str::uuid();

    $enrollment = EnrollRewardMemberAction::run(
        $program,
        'user',
        $memberId,
        $version,
        'member-portal',
        CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    expect($enrollment->status)->toBe(RewardEnrollmentStatus::Active)
        ->and($enrollment->acceptances()->count())->toBe(1)
        ->and($enrollment->periods()->count())->toBe(1)
        ->and($enrollment->current_acceptance_id)->not->toBeNull();

    $acceptance = $enrollment->acceptances()->first();
    $period = $enrollment->periods()->first();

    expect($acceptance->seq)->toBe(1)
        ->and($acceptance->policy_version_id)->toBe((string) $version->getKey())
        ->and($acceptance->material_terms_hash)->toBe($version->material_terms_hash)
        ->and($period->seq)->toBe(1)
        ->and($period->join_acceptance_id)->toBe((string) $acceptance->getKey())
        ->and($period->left_at)->toBeNull();
});

it('is idempotent for active members under identical frozen terms', function () {
    [$program, $version] = seedVersionedProgram('core-idem');

    $memberId = (string) Str::uuid();
    $first = EnrollRewardMemberAction::run($program, 'user', $memberId, $version);
    $second = EnrollRewardMemberAction::run($program, 'user', $memberId, $version);

    expect((string) $second->getKey())->toBe((string) $first->getKey())
        ->and($second->acceptances()->count())->toBe(1)
        ->and($second->periods()->count())->toBe(1);
});

it('appends reacceptance prospectively on material terms change', function () {
    [$program, $v1] = seedVersionedProgram('core-reacc');

    $memberId = (string) Str::uuid();
    $enrollment = EnrollRewardMemberAction::run($program, 'user', $memberId, $v1);

    $terms2 = RewardCoreTestFixtures::terms();
    $terms2['version_label'] = 'draft-2';
    $terms2['material']['earn_basis'] = 'Changed material basis.';

    $effective = CarbonImmutable::parse('2026-07-01T00:00:00Z');

    $v2 = PublishRewardProgramVersionAction::run(
        $program,
        $effective,
        RewardCoreTestFixtures::rules(),
        $terms2,
        null,
        null,
        null,
        $effective,
    );

    expect($v2->material_terms_hash)->not->toBe($v1->material_terms_hash);

    $reaccepted = EnrollRewardMemberAction::run($program, 'user', $memberId, $v2);

    expect($reaccepted->acceptances()->count())->toBe(2)
        ->and($reaccepted->periods()->count())->toBe(1);

    $rows = $reaccepted->acceptances()->orderBy('seq')->get();

    expect($rows[0]->material_terms_hash)->toBe($v1->material_terms_hash)
        ->and($rows[1]->material_terms_hash)->toBe($v2->material_terms_hash)
        ->and($rows[1]->seq)->toBe(2)
        ->and((string) $reaccepted->current_acceptance_id)->toBe((string) $rows[1]->getKey());
});

it('always appends reacceptance rows even for identical terms', function () {
    [$program, $version] = seedVersionedProgram('core-reacc-same');

    $memberId = (string) Str::uuid();
    $enrollment = EnrollRewardMemberAction::run($program, 'user', $memberId, $version);

    $again = ReacceptRewardTermsAction::run($enrollment, $version);

    expect($again->seq)->toBe(2)
        ->and($enrollment->refresh()->acceptances()->count())->toBe(2)
        ->and((string) $enrollment->current_acceptance_id)->toBe((string) $again->getKey());
});

it('leaves and rejoins with preserved frozen history', function () {
    [$program, $version] = seedVersionedProgram('core-rejoin');

    $memberId = (string) Str::uuid();
    $enrollment = EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, null, CarbonImmutable::parse('2026-06-10T00:00:00Z')
    );

    $left = LeaveRewardProgramAction::run($enrollment, 'taking-a-break', CarbonImmutable::parse('2026-06-20T00:00:00Z'));

    expect($left->status)->toBe(RewardEnrollmentStatus::Left)
        ->and($left->left_at)->not->toBeNull()
        ->and($left->periods()->first()->left_at)->not->toBeNull()
        ->and($left->periods()->first()->leave_reason)->toBe('taking-a-break');

    $firstLeftAt = CarbonImmutable::parse($left->left_at)->toIso8601String();

    $rejoined = EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, null, CarbonImmutable::parse('2026-07-10T00:00:00Z')
    );

    expect($rejoined->status)->toBe(RewardEnrollmentStatus::Active)
        ->and($rejoined->acceptances()->count())->toBe(2)
        ->and($rejoined->periods()->count())->toBe(2)
        ->and(CarbonImmutable::parse($rejoined->left_at)->toIso8601String())->toBe($firstLeftAt);

    $periods = $rejoined->periods()->orderBy('seq')->get();

    expect($periods[0]->left_at)->not->toBeNull()
        ->and($periods[1]->left_at)->toBeNull()
        ->and($periods[1]->join_acceptance_id)->toBe((string) $rejoined->current_acceptance_id);

    $again = LeaveRewardProgramAction::run($rejoined);

    expect($again->status)->toBe(RewardEnrollmentStatus::Left)
        ->and(CarbonImmutable::parse($again->left_at)->toIso8601String())->toBe($firstLeftAt);
});

it('rejects non-member identities and foreign policy versions', function () {
    [$program, $version] = seedVersionedProgram('core-reject');

    expect(fn () => EnrollRewardMemberAction::run($program, 'guest', (string) Str::uuid(), $version))
        ->toThrow(InvalidArgumentException::class, 'no unclaimed-guest');

    $other = RewardProgram::factory()->create(['family_key' => 'core-other-'.Str::lower(Str::random(6))]);
    $foreignEffective = CarbonImmutable::parse('2026-06-01T00:00:00Z');
    $foreign = PublishRewardProgramVersionAction::run(
        $other,
        $foreignEffective,
        RewardCoreTestFixtures::rules(),
        RewardCoreTestFixtures::terms(),
        null,
        null,
        null,
        $foreignEffective,
    );

    expect(fn () => EnrollRewardMemberAction::run($program, 'user', (string) Str::uuid(), $foreign))
        ->toThrow(InvalidArgumentException::class, 'does not belong');
});

it('freezes acceptance rows and enrollment identity', function () {
    [$program, $version] = seedVersionedProgram('core-frozen');

    $enrollment = EnrollRewardMemberAction::run($program, 'user', (string) Str::uuid(), $version);
    $acceptance = $enrollment->acceptances()->first();

    expect(fn () => $acceptance->forceFill(['terms_label' => 'mutated'])->save())->toThrow(LogicException::class);
    expect(fn () => $enrollment->forceFill(['member_id' => (string) Str::uuid()])->save())->toThrow(LogicException::class);
    expect(fn () => $enrollment->delete())->toThrow(LogicException::class);
});

it('rejects recognition and cash modes only via their own rules, not enrollment', function () {
    $program = RewardProgram::factory()->create([
        'family_key' => 'core-rec-'.Str::lower(Str::random(6)),
        'mode' => RewardProgramMode::Recognition,
    ]);
    $recEffective = CarbonImmutable::parse('2026-06-01T00:00:00Z');
    $version = PublishRewardProgramVersionAction::run(
        $program,
        $recEffective,
        RewardCoreTestFixtures::rules(RewardProgramMode::Recognition),
        RewardCoreTestFixtures::terms(RewardProgramMode::Recognition),
        null,
        null,
        null,
        $recEffective,
    );

    $enrollment = EnrollRewardMemberAction::run($program, 'user', (string) Str::uuid(), $version);

    expect($enrollment->exists)->toBeTrue()
        ->and(RewardEnrollment::query()->where('program_id', $program->getKey())->count())->toBe(1);
});
