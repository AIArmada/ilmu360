<?php

use App\Actions\Rewards\CreateRewardProgramAction;
use App\Actions\Rewards\PublishRewardProgramVersionAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardProgramStatus;
use App\Models\RewardProgram;
use App\Models\RewardProgramVersion;
use App\Support\Rewards\RewardVersionResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Core\Support\RewardCoreTestFixtures;

it('creates disabled draft programs with explicit ownership scope', function () {
    $program = CreateRewardProgramAction::run(
        familyKey: 'core-lifecycle-'.Str::lower(Str::random(6)),
        title: 'Lifecycle program',
        mode: RewardProgramMode::Points,
        ownerType: 'platform',
        ownerId: null,
        purpose: 'test-lifecycle',
    );

    expect($program->status)->toBe(RewardProgramStatus::Draft)
        ->and($program->current_version_id)->toBeNull()
        ->and($program->ready_at)->toBeNull();
});

it('rejects invalid ownership scope and duplicate families', function () {
    $family = 'core-dup-'.Str::lower(Str::random(6));

    CreateRewardProgramAction::run($family, 'T', RewardProgramMode::Points, 'platform', null, 'p');

    expect(fn () => CreateRewardProgramAction::run($family, 'T2', RewardProgramMode::Points, 'platform', null, 'p'))
        ->toThrow(InvalidArgumentException::class, 'already exists');

    expect(fn () => CreateRewardProgramAction::run('bad family!', 'T', RewardProgramMode::Points, 'platform', null, 'p'))
        ->toThrow(InvalidArgumentException::class, 'Family key');

    expect(fn () => CreateRewardProgramAction::run('core-x-'.Str::lower(Str::random(6)), 'T', RewardProgramMode::Points, 'institution', null, 'p'))
        ->toThrow(InvalidArgumentException::class, 'owner UUID');
});

it('publishes an append-only version timeline with derived windows', function () {
    $program = RewardProgram::factory()->create(['family_key' => 'core-timeline-'.Str::lower(Str::random(6))]);
    $publish = app(PublishRewardProgramVersionAction::class);

    $t1 = CarbonImmutable::parse('2026-06-01T00:00:00Z');
    $t2 = CarbonImmutable::parse('2026-07-01T00:00:00Z');

    $v1 = $publish->handle($program, $t1, RewardCoreTestFixtures::rules(), RewardCoreTestFixtures::terms(), 'initial', null, null, $t1);
    $v2 = $publish->handle($program, $t2, RewardCoreTestFixtures::rules(), RewardCoreTestFixtures::terms(), 'refresh', null, null, $t2);

    expect($v1->version)->toBe(1)
        ->and($v2->version)->toBe(2)
        ->and($program->refresh()->current_version_id)->toBe((string) $v2->getKey())
        ->and($program->publications()->count())->toBe(2);

    $timeline = [
        ['id' => (string) $v1->getKey(), 'version' => 1, 'effective_from' => $t1],
        ['id' => (string) $v2->getKey(), 'version' => 2, 'effective_from' => $t2],
    ];

    expect(RewardVersionResolver::resolveAt($timeline, CarbonImmutable::parse('2026-05-01T00:00:00Z')))->toBeNull()
        ->and(RewardVersionResolver::resolveAt($timeline, CarbonImmutable::parse('2026-06-15T00:00:00Z'))['version'])->toBe(1)
        ->and(RewardVersionResolver::resolveAt($timeline, CarbonImmutable::parse('2026-07-01T00:00:00Z'))['version'])->toBe(2)
        ->and(RewardVersionResolver::windowEndFor($timeline, (string) $v1->getKey())->toIso8601String())->toBe($t2->toIso8601String())
        ->and(RewardVersionResolver::windowEndFor($timeline, (string) $v2->getKey()))->toBeNull();
});

it('rejects backdated publications into published windows', function () {
    $program = RewardProgram::factory()->create(['family_key' => 'core-backdate-'.Str::lower(Str::random(6))]);
    $publish = app(PublishRewardProgramVersionAction::class);
    $t1 = CarbonImmutable::parse('2026-06-01T00:00:00Z');

    $publish->handle($program, $t1, RewardCoreTestFixtures::rules(), RewardCoreTestFixtures::terms(), null, null, null, $t1);

    expect(fn () => $publish->handle($program, CarbonImmutable::parse('2026-06-01T00:00:00Z'), RewardCoreTestFixtures::rules(), RewardCoreTestFixtures::terms(), null, null, null, CarbonImmutable::parse('2026-06-01T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'strictly after');
});

it('walks draft to active with immutable first-transition timestamps', function () {
    $program = RewardProgram::factory()->create(['family_key' => 'core-walk-'.Str::lower(Str::random(6))]);
    $transition = app(TransitionRewardProgramStatusAction::class);

    expect(fn () => $transition->handle($program, RewardProgramStatus::Ready))
        ->toThrow(InvalidArgumentException::class, 'no-published-version');

    $effective = CarbonImmutable::parse('2026-06-01T00:00:00Z');

    PublishRewardProgramVersionAction::run($program, $effective, RewardCoreTestFixtures::rules(), RewardCoreTestFixtures::terms(), null, null, null, $effective);

    $ready = $transition->handle($program, RewardProgramStatus::Ready);
    $active = $transition->handle($program, RewardProgramStatus::Active);

    expect($ready->ready_at)->not->toBeNull()
        ->and($active->active_at)->not->toBeNull()
        ->and($active->status)->toBe(RewardProgramStatus::Active);

    $firstActiveAt = $active->active_at;

    $paused = $transition->handle($program, RewardProgramStatus::Paused);
    $resumed = $transition->handle($program, RewardProgramStatus::Active);

    expect($paused->paused_at)->not->toBeNull()
        ->and($resumed->active_at->toIso8601String())->toBe(CarbonImmutable::parse($firstActiveAt)->toIso8601String())
        ->and($resumed->paused_at->toIso8601String())->toBe(CarbonImmutable::parse($paused->paused_at)->toIso8601String());

    $closed = $transition->handle($program, RewardProgramStatus::Closed);

    expect($closed->closed_at)->not->toBeNull()
        ->and(fn () => $transition->handle($program, RewardProgramStatus::Active))
        ->toThrow(InvalidArgumentException::class, 'Invalid reward program status transition');
});

it('rejects invalid lifecycle jumps', function () {
    $program = RewardProgram::factory()->create(['family_key' => 'core-jump-'.Str::lower(Str::random(6))]);
    $effective = CarbonImmutable::parse('2026-06-01T00:00:00Z');

    PublishRewardProgramVersionAction::run($program, $effective, RewardCoreTestFixtures::rules(), RewardCoreTestFixtures::terms(), null, null, null, $effective);

    expect(fn () => TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active))
        ->toThrow(InvalidArgumentException::class, 'Invalid reward program status transition');
});

it('builds mode-coherent version fixtures', function () {
    $version = RewardProgramVersion::factory()->ofMode(RewardProgramMode::Cash)->create();

    expect($version->program->mode)->toBe(RewardProgramMode::Cash)
        ->and($version->rules['mode'])->toBe(RewardProgramMode::Cash->value);
});

it('freezes published versions and blocks program deletion', function () {
    $version = RewardProgramVersion::factory()->create();

    expect(fn () => $version->forceFill(['reason' => 'x'])->save())->toThrow(LogicException::class);
    expect(fn () => $version->delete())->toThrow(LogicException::class);
    expect(fn () => $version->program->delete())->toThrow(LogicException::class);
    expect(fn () => $version->program->forceFill(['mode' => RewardProgramMode::Cash])->save())->toThrow(LogicException::class);
});
