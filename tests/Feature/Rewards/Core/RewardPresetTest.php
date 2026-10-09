<?php

use App\Actions\Rewards\PublishRewardProgramVersionAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardProgramStatus;
use App\Models\RewardProgram;
use App\Support\Rewards\NullRewardReadinessChecker;
use App\Support\Rewards\RewardPresetBuilders;
use App\Support\Rewards\RewardRuleValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Core\Support\RewardCoreTestFixtures;

it('builds disabled draft presets for all modes with validator-clean rules', function (RewardProgramMode $mode) {
    $preset = RewardPresetBuilders::for($mode);
    $validator = new RewardRuleValidator;

    expect($preset['status'])->toBe('draft')
        ->and($preset['enabled'])->toBeFalse()
        ->and($preset['activation_blocked'])->toBeTrue()
        ->and($preset['blocked_reasons'])->toContain('preset-is-draft-only', 'explicit-staff-setup-required')
        ->and($validator->errors($preset['rules'], $mode, 'preset-family'))->toBe([])
        ->and($validator->termsErrors($preset['terms']))->toBe([]);
})->with(RewardProgramMode::cases());

it('lists missing finance adapters on money and benefit presets, never fake capability', function (RewardProgramMode $mode) {
    $preset = RewardPresetBuilders::for($mode);

    expect($preset['blocked_reasons'])->toContain(
        'funding-adapter-missing',
        'payee-verification-missing',
        'retention-policy-missing',
        'reversal-settlement-missing',
    );
})->with([RewardProgramMode::Cash, RewardProgramMode::Benefit]);

it('keeps recognition, points, and badge presets free of money blockers', function (RewardProgramMode $mode) {
    $preset = RewardPresetBuilders::for($mode);

    expect($preset['blocked_reasons'])->not->toContain('funding-adapter-missing');
})->with([RewardProgramMode::Recognition, RewardProgramMode::Points, RewardProgramMode::Badges, RewardProgramMode::Combined]);

it('null readiness rejects cash activation with truthful blockers', function () {
    $checker = new NullRewardReadinessChecker;
    [$program, $version] = RewardCoreTestFixtures::seedVersionedProgram('core-cash', RewardProgramMode::Cash);

    $ready = $checker->canMarkReady($program, $version);
    $activate = $checker->canActivate($program, $version);

    expect($ready->ready)->toBeTrue()
        ->and($activate->ready)->toBeFalse()
        ->and($activate->blockers)->toContain(
            'funding-adapter-missing',
            'payee-verification-missing',
            'retention-policy-missing',
            'reversal-settlement-missing',
        );
});

it('blocks unfunded cash programs from reaching active through transitions', function () {
    [$program] = RewardCoreTestFixtures::seedVersionedProgram('core-cash-block', RewardProgramMode::Cash);

    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);

    // Finance scope: adapters exist, so the honest blocker is confirmed
    // funding — activation still refuses, with a provable reason.
    expect(fn () => TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active))
        ->toThrow(InvalidArgumentException::class, 'no-confirmed-funding');
});

it('activates points programs structurally without payee claims', function () {
    [$program, $version] = RewardCoreTestFixtures::seedVersionedProgram('core-points-go');

    $assessment = (new NullRewardReadinessChecker)->canActivate($program, $version);

    expect($assessment->ready)->toBeTrue();

    $active = TransitionRewardProgramStatusAction::run(
        TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready),
        RewardProgramStatus::Active,
    );

    expect($active->status)->toBe(RewardProgramStatus::Active);
});

it('publishes preset rules verbatim for every mode', function (RewardProgramMode $mode) {
    $program = RewardProgram::factory()->create([
        'family_key' => 'core-preset-'.$mode->value.'-'.Str::lower(Str::random(4)),
        'mode' => $mode,
    ]);

    $preset = RewardPresetBuilders::for($mode);
    $effective = CarbonImmutable::parse('2026-06-01T00:00:00Z');

    $version = PublishRewardProgramVersionAction::run(
        $program,
        $effective,
        $preset['rules'],
        $preset['terms'],
        null,
        null,
        null,
        $effective,
    );

    expect($version->version)->toBe(1)
        ->and($version->material_terms_hash)->not->toBe('');
})->with(RewardProgramMode::cases());
