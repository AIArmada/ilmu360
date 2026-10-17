<?php

declare(strict_types=1);

use AIArmada\Referrals\Enums\ReferralProgramStatus;
use AIArmada\Referrals\Models\ReferralProgram;
use AIArmada\Rewards\Actions\PublishRewardProgramVersionAction;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Models\RewardBenefitItem;
use AIArmada\Rewards\Models\RewardProgram;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

it('refuses to provision local Dawah examples outside the local environment', function (): void {
    config()->set('app.env', 'testing');

    $this->artisan('app:dawah:activate-local-examples')
        ->expectsOutput('This activation command can run only when APP_ENV=local.')
        ->assertExitCode(1);

    expect(RewardProgram::query()->where('family_key', 'dawah-points-v1')->exists())->toBeFalse()
        ->and(ReferralProgram::query()->where('slug', 'ilmu360-dawah-community')->exists())->toBeFalse();
});

it('provisions repeatable active points, referral, benefit, milestones and demo logins locally', function (): void {
    app()->detectEnvironment(static fn (): string => 'local');
    User::factory()->create(['email' => 'superadmin@ilmu360.com']);

    $exitCode = Artisan::call('app:dawah:activate-local-examples');
    expect($exitCode)->toBe(0, Artisan::output());

    $program = RewardProgram::query()->where('family_key', 'dawah-points-v1')->firstOrFail();
    $version = $program->versions()->reorderDesc('version')->firstOrFail();
    $referral = ReferralProgram::query()->where('slug', 'ilmu360-dawah-community')->firstOrFail();
    $benefit = RewardBenefitItem::query()
        ->where('program_id', $program->getKey())
        ->where('benefit_key', 'community-learning-pack')
        ->firstOrFail();

    expect($program->mode)->toBe(RewardProgramMode::Points)
        ->and($program->status)->toBe(RewardProgramStatus::Active)
        ->and(data_get($version->rules, 'components.0.variants.0.points'))->toBe(3)
        ->and(data_get($version->rules, 'components.0.variants.1.points'))->toBe(5)
        ->and(data_get($version->rules, 'components.0.variants.2.min_verification'))->toBe('staff_verified')
        ->and(data_get($version->rules, 'components.0.variants.2.points'))->toBe(15)
        ->and(data_get($version->rules, 'caps'))->toHaveCount(3)
        ->and(data_get($version->rules, 'milestones'))->toHaveCount(5)
        ->and(data_get($version->rules, 'milestones.0.reward.type'))->toBe('points')
        ->and(data_get($version->rules, 'milestones.4.reward.type'))->toBe('points')
        ->and($referral->status)->toBe(ReferralProgramStatus::Active)
        ->and($referral->qualifier_outcome)->toBe('first_accepted_registration_or_staff_verified_checkin')
        ->and(data_get($referral->referrer_reward, 'family_key'))->toBe('dawah-points-v1')
        ->and(data_get($referral->referee_reward, 'family_key'))->toBe('dawah-points-v1')
        ->and($benefit->cost_points)->toBe(500)
        ->and($benefit->cost_unit)->toBe('pts')
        ->and(Hash::check('Ilmu360Demo!2026', User::query()->where('email', 'sharer.demo@ilmu360.test')->firstOrFail()->password))->toBeTrue()
        ->and(Hash::check('Ilmu360Demo!2026', User::query()->where('email', 'visitor.demo@ilmu360.test')->firstOrFail()->password))->toBeTrue();

    $exitCode = Artisan::call('app:dawah:activate-local-examples');
    expect($exitCode)->toBe(0, Artisan::output());

    expect(RewardProgram::query()->where('family_key', 'dawah-points-v1')->count())->toBe(1)
        ->and($program->versions()->count())->toBe(1)
        ->and(ReferralProgram::query()->where('slug', 'ilmu360-dawah-community')->count())->toBe(1)
        ->and(RewardBenefitItem::query()->where('program_id', $program->getKey())->count())->toBe(1)
        ->and(User::query()->whereIn('email', ['sharer.demo@ilmu360.test', 'visitor.demo@ilmu360.test'])->count())->toBe(2);
});

it('publishes corrected rules when rerun over stale local examples', function (): void {
    app()->detectEnvironment(static fn (): string => 'local');
    User::factory()->create(['email' => 'superadmin@ilmu360.com']);

    expect(Artisan::call('app:dawah:activate-local-examples'))->toBe(0, Artisan::output());

    $program = RewardProgram::query()->where('family_key', 'dawah-points-v1')->firstOrFail();
    $version = $program->versions()->reorderDesc('version')->firstOrFail();
    $staleRules = $version->rules;

    foreach ($staleRules['components'] as &$component) {
        foreach ($component['variants'] as &$variant) {
            $variant['roles'][] = 'purchaser';
        }
        unset($variant);
    }
    unset($component);

    foreach ($staleRules['milestones'] as &$milestone) {
        $milestone['roles'][] = 'purchaser';
    }
    unset($milestone);

    expect(data_get($staleRules, 'components.0.variants.0.roles'))
        ->toBe(['participant', 'sharer', 'purchaser']);

    $publishedAt = CarbonImmutable::now('UTC');
    $effectiveFrom = CarbonImmutable::parse($version->effective_from)->setTimezone('UTC')->max($publishedAt)->addSecond();

    app(PublishRewardProgramVersionAction::class)->handle(
        $program,
        $effectiveFrom,
        $staleRules,
        $version->terms,
        reason: 'Seed a previously published stale role set.',
        publishedByType: 'test',
        publishedAt: $publishedAt,
    );

    $stalePublishedVersion = $program->versions()->reorderDesc('version')->firstOrFail();

    expect(data_get($stalePublishedVersion->rules, 'components.0.variants.0.roles'))
        ->toBe(['participant', 'sharer', 'purchaser']);

    expect(Artisan::call('app:dawah:activate-local-examples'))->toBe(0, Artisan::output());

    $correctedVersion = $program->versions()->reorderDesc('version')->firstOrFail();

    expect(data_get($correctedVersion->rules, 'components.0.variants.0.roles'))
        ->toBe(['participant', 'sharer'])
        ->and($program->versions()->count())->toBe(3)
        ->and(data_get($correctedVersion->rules, 'milestones.0.reward.type'))->toBe('points');

    foreach (data_get($correctedVersion->rules, 'components.0.variants', []) as $variant) {
        expect($variant['roles'])->toBe(['participant', 'sharer']);
    }

    foreach (data_get($correctedVersion->rules, 'milestones', []) as $milestone) {
        expect($milestone['roles'])->toBe(['participant', 'sharer']);
    }

    expect(Artisan::call('app:dawah:activate-local-examples'))->toBe(0, Artisan::output())
        ->and($program->versions()->count())->toBe(3);
});

it('reports clearly when an existing local referral cannot be reactivated', function (): void {
    app()->detectEnvironment(static fn (): string => 'local');
    User::factory()->create(['email' => 'superadmin@ilmu360.com']);

    expect(Artisan::call('app:dawah:activate-local-examples'))->toBe(0, Artisan::output());

    $referral = ReferralProgram::query()->where('slug', 'ilmu360-dawah-community')->firstOrFail();
    $referral->forceFill(['status' => ReferralProgramStatus::Paused])->save();

    expect(Artisan::call('app:dawah:activate-local-examples'))->toBe(1);

    expect(Artisan::output())
        ->toContain('Existing referral program [ilmu360-dawah-community] has status [paused]')
        ->toContain('no supported status transition')
        ->not->toContain('immutable after creation');

    expect($referral->fresh()->status)->toBe(ReferralProgramStatus::Paused);
});
