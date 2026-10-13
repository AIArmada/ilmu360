<?php

declare(strict_types=1);

use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Actions\OpenRewardAppealAction;
use AIArmada\Rewards\Actions\TransitionRewardProgramStatusAction;
use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Enums\RewardComponent;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Jobs\EvaluateRewardEntitlementJob;
use AIArmada\Rewards\Models\RewardAppeal;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardProgram;
use App\Models\User;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Rewards\Host\Support\RewardHostFixtures;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setPermissionsTeamId(null);
    $this->seed(RoleSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function policyAssignAdmin(User $user): void
{
    $previousTeam = getPermissionsTeamId();
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->assignRole('admin');
    setPermissionsTeamId($previousTeam);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function policySeed(string $prefix): array
{
    $member = User::factory()->create();
    $other = User::factory()->create();
    $staff = User::factory()->create();
    policyAssignAdmin($staff);

    [$program] = (function () use ($prefix, $member): array {
        [$program, $version] = RewardLedgerFixtures::seedVersionedProgram($prefix, RewardProgramMode::Recognition);
        TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
        TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);
        EnrollRewardMemberAction::run($program->refresh(), 'user', (string) $member->getKey(), $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'));

        return [$program->refresh(), $version];
    })();

    $actor = User::factory()->create();
    $key = ShareOutcomeBusinessKey::signup((string) $actor->getKey());
    RewardHostFixtures::receipt($key, 'user:'.$actor->getKey(), [
        'attribution_id' => (string) Str::uuid(),
        'outcome_type' => 'signup',
        'occurred_at' => CarbonImmutable::now('UTC')->subMinutes(5),
    ]);
    RewardHostFixtures::conversion($key, [
        'sharer_user_id' => (string) $member->getKey(),
        'actor_user_id' => (string) $actor->getKey(),
        'occurred_at' => CarbonImmutable::now('UTC')->subMinutes(5),
    ]);
    EvaluateRewardEntitlementJob::dispatchSync($key);

    $award = RewardAward::query()
        ->where('program_id', $program->getKey())
        ->where('component', RewardComponent::Recognition->value)
        ->firstOrFail();

    return [$program, $member, $other, $staff, $award];
}

it('authorizes program reads for members and management for staff', function (): void {
    [$program, $member, $other] = policySeed('policy-program');

    expect(Gate::forUser($member)->allows('viewAny', RewardProgram::class))->toBeTrue();
    expect(Gate::forUser($member)->allows('view', $program))->toBeTrue();
    expect(Gate::forUser($member)->allows('enroll', $program))->toBeTrue();
    expect(Gate::forUser($member)->allows('leave', $program))->toBeTrue();
    expect(Gate::forUser($member)->allows('create', RewardProgram::class))->toBeFalse();
    expect(Gate::forUser($member)->allows('update', $program))->toBeFalse();
    expect(Gate::forUser($other)->allows('leave', $program))->toBeFalse();

    $staff = User::factory()->create();
    policyAssignAdmin($staff);
    expect(Gate::forUser($staff)->allows('create', RewardProgram::class))->toBeTrue();
    expect(Gate::forUser($staff)->allows('transitionStatus', $program))->toBeTrue();

    [$draft] = RewardLedgerFixtures::seedVersionedProgram('policy-draft', RewardProgramMode::Recognition);
    expect(Gate::forUser($member)->allows('view', $draft->refresh()))->toBeFalse();
    expect(Gate::forUser($member)->allows('enroll', $draft->refresh()))->toBeFalse();
});

it('denies guests every program surface', function (): void {
    [$program] = policySeed('policy-guest');

    expect(Gate::allows('viewAny', RewardProgram::class))->toBeFalse();
    expect(Gate::allows('view', $program))->toBeFalse();
});

it('scopes award reads to staff and the recipient', function (): void {
    [$program, $member, $other, $staff, $award] = policySeed('policy-award');

    expect(Gate::forUser($member)->allows('view', $award))->toBeTrue();
    expect(Gate::forUser($member)->allows('openAppeal', $award))->toBeTrue();
    expect(Gate::forUser($member)->allows('approve', $award))->toBeFalse();
    expect(Gate::forUser($other)->allows('view', $award))->toBeFalse();
    expect(Gate::forUser($other)->allows('openAppeal', $award))->toBeFalse();

    expect(Gate::forUser($staff)->allows('view', $award))->toBeTrue();
    expect(Gate::forUser($staff)->allows('review', $award))->toBeTrue();
    expect(Gate::forUser($staff)->allows('approve', $award))->toBeTrue();
    expect(Gate::forUser($staff)->allows('reverse', $award))->toBeTrue();
    expect(Gate::forUser($staff)->allows('dismissFraud', $award))->toBeTrue();
});

it('denies staff self-decisions on recipient-owned awards', function (): void {
    [$program, $member] = policySeed('policy-self');

    policyAssignAdmin($member);

    $award = RewardAward::query()->where('program_id', $program->getKey())->firstOrFail();

    expect($award->status)->toBe(RewardAwardStatus::Approved);
    expect(Gate::forUser($member)->allows('approve', $award))->toBeFalse();
    expect(Gate::forUser($member)->allows('reverse', $award))->toBeFalse();
    expect(Gate::forUser($member)->allows('dismissFraud', $award))->toBeFalse();
    expect(Gate::forUser($member)->allows('view', $award))->toBeTrue();
});

it('authorizes appeals for involved members and deciding staff', function (): void {
    [$program, $member, $other, $staff, $award] = policySeed('policy-appeal');

    $appeal = OpenRewardAppealAction::run(
        $award, 'user', (string) $member->getKey(), 'member',
        'The contribution evidence looks wrong.',
    );

    expect($appeal)->toBeInstanceOf(RewardAppeal::class);
    expect(Gate::forUser($member)->allows('view', $appeal))->toBeTrue();
    expect(Gate::forUser($other)->allows('view', $appeal))->toBeFalse();
    expect(Gate::forUser($other)->allows('create', [RewardAppeal::class, $award]))->toBeFalse();
    expect(Gate::forUser($member)->allows('create', [RewardAppeal::class, $award]))->toBeTrue();
    expect(Gate::forUser($staff)->allows('decide', $appeal))->toBeTrue();

    $staffAwardRecipient = User::factory()->create();
    policyAssignAdmin($staffAwardRecipient);
    expect(Gate::forUser($staffAwardRecipient)->allows('decide', $appeal))->toBeTrue();
});
