<?php

declare(strict_types=1);

use AIArmada\FilamentRewards\Resources\RewardPayeeResource\Pages\ListRewardPayees;
use AIArmada\FilamentRewards\Resources\RewardProgramResource\Pages\CreateRewardProgram;
use AIArmada\FilamentRewards\Resources\RewardProgramResource\Pages\ViewRewardProgram;
use AIArmada\Rewards\Actions\CreateRewardProgramAction;
use AIArmada\Rewards\Actions\PublishRewardProgramVersionAction;
use AIArmada\Rewards\Actions\RegisterRewardPayeeAction;
use AIArmada\Rewards\Enums\RewardPayeeStatus;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Models\RewardPayee;
use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Rewards\Support\RewardPublishAssembler;
use AIArmada\Rewards\Support\RewardRuleValidator;
use App\Models\Institution;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setPermissionsTeamId(null);
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->staff = User::factory()->create();
    $this->staff->assignRole('admin');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * @return array<string, mixed>
 */
function operabilityFormData(RewardProgramMode $mode): array
{
    $base = [
        'eligibility_outcomes' => ['signup', 'event_registration'],
        'eligibility_categories' => '',
        'min_verification' => 'accepted',
        'first_only' => false,
        'exclude_self_credit' => true,
        'require_enrollment' => true,
        'require_claimed_identity' => true,
        'preclaim_lookback_days' => 30,
        'hold_days' => 0,
        'cap_scope_1' => 'recipient_source_component',
        'cap_limit_1' => 1,
        'ambiguous_device' => true,
        'manual_review_outcomes' => [],
        'terms_label' => 'Member terms',
        'terms_version_label' => 'v1',
        'terms_body' => 'Earn only on verified eligible outcomes.',
        'terms_earn_basis' => 'Verified eligible outcomes only.',
        'terms_changes' => 'Changes apply prospectively after reacceptance.',
        'terms_no_ranking' => 'No public ranking.',
    ];

    return match ($mode) {
        RewardProgramMode::Recognition => [...$base, 'recognition_template' => 'quiet-thanks'],
        RewardProgramMode::Points => [...$base, 'points_amount' => 10, 'points_unit' => 'pts'],
        RewardProgramMode::Badges => [...$base, 'badge_key' => 'first-share'],
        RewardProgramMode::Cash => [...$base,
            'cash_minor_units' => 500,
            'cash_currency' => 'MYR',
            'eligibility_outcomes' => ['event_checkin', 'event_submission'],
            'min_verification' => 'staff_verified',
        ],
        RewardProgramMode::Benefit => [...$base,
            'benefit_key' => 'ebook-credit',
            'benefit_conditions' => "Good standing\nOne redemption per award",
            'eligibility_outcomes' => ['event_checkin', 'event_submission'],
            'min_verification' => 'staff_verified',
        ],
        RewardProgramMode::Combined => [...$base,
            'component_recognition' => true,
            'component_points' => true,
            'component_badge' => true,
            'component_cash' => false,
            'component_benefit' => false,
            'recognition_template' => 'quiet-thanks',
            'points_amount' => 10,
            'points_unit' => 'pts',
            'badge_key' => 'first-share',
        ],
    };
}

it('publishes every mode from structured controls without hand-written JSON', function (RewardProgramMode $mode) {
    $program = CreateRewardProgramAction::run(
        'op-'.$mode->value, 'Operability '.$mode->value, $mode, null, null, 'Operability scope.',
    );

    $payload = RewardPublishAssembler::assemble($mode, operabilityFormData($mode));

    expect((new RewardRuleValidator)->errors($payload['rules'], $mode, (string) $program->family_key))->toBe([])
        ->and((new RewardRuleValidator)->termsErrors($payload['terms'], $payload['rules']))->toBe([]);

    $version = PublishRewardProgramVersionAction::run(
        $program,
        CarbonImmutable::now('UTC')->addDay(),
        $payload['rules'],
        $payload['terms'],
        'operability proof',
        'user',
        (string) $this->staff->getKey(),
    );

    expect((int) $version->version)->toBe(1)
        ->and((string) $version->material_terms_hash)->not->toBe('');
})->with(RewardProgramMode::cases());

it('accepts Livewire-dehydrated whole floats as the integers typed', function () {
    $payload = RewardPublishAssembler::assemble(RewardProgramMode::Points, [
        ...operabilityFormData(RewardProgramMode::Points),
        'points_amount' => 10.0,
        'preclaim_lookback_days' => 30.0,
        'hold_days' => 0.0,
        'cap_limit_1' => 1.0,
    ]);

    expect($payload['rules']['components'][0]['points'])->toBe(10)
        ->and($payload['rules']['windows']['preclaim_lookback_days'])->toBe(30)
        ->and((new RewardRuleValidator)->errors($payload['rules'], RewardProgramMode::Points, 'op-float'))->toBe([]);
});

it('refuses to publish with no enabled component', function () {
    expect(fn () => RewardPublishAssembler::assemble(RewardProgramMode::Combined, [
        ...operabilityFormData(RewardProgramMode::Combined),
        'component_recognition' => false,
        'component_points' => false,
        'component_badge' => false,
    ]))->toThrow(InvalidArgumentException::class, 'At least one reward component');
});

it('renders the publish page with structured controls and no JSON boxes', function () {
    $program = CreateRewardProgramAction::run(
        'op-render', 'Operability render', RewardProgramMode::Points, null, null, 'Render scope.',
    );

    Livewire::actingAs($this->staff)
        ->test(ViewRewardProgram::class, ['record' => (string) $program->getKey()])
        ->assertOk()
        ->assertSee('Publish version');
});

it('creates a program with a searched owner instead of a raw UUID', function () {
    $institution = Institution::factory()->create(['name' => 'Operability House']);

    Livewire::actingAs($this->staff)
        ->test(CreateRewardProgram::class)
        ->fillForm([
            'family_key' => 'op-owned',
            'title' => 'Owned program',
            'mode' => RewardProgramMode::Points->value,
            'owner_type' => 'institution',
            'owner_record_id' => (string) $institution->getKey(),
            'purpose' => 'Owned scope.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $program = RewardProgram::query()->where('family_key', 'op-owned')->firstOrFail();

    expect((string) $program->owner_type)->toBe('institution')
        ->and((string) $program->owner_id)->toBe((string) $institution->getKey());
});

it('verifies and rejects payee registrations from the staff table', function () {
    $member = User::factory()->create();
    $memberId = (string) $member->getKey();

    $pending = RegisterRewardPayeeAction::run(
        'user', $memberId, 'manual', 'op-verify-1', 'Desk V1', 'user', $memberId, 'member',
    );

    Livewire::actingAs($this->staff)
        ->test(ListRewardPayees::class)
        ->callTableAction('verify', $pending->getKey())
        ->assertHasNoErrors();

    expect($pending->refresh()->status)->toBe(RewardPayeeStatus::Superseded)
        ->and(RewardPayee::query()
            ->where('recipient_id', $memberId)
            ->where('status', RewardPayeeStatus::Verified->value)
            ->count())->toBe(1);

    $second = RegisterRewardPayeeAction::run(
        'user', $memberId, 'manual', 'op-verify-2', 'Desk V2', 'user', $memberId, 'member',
    );

    Livewire::actingAs($this->staff)
        ->test(ListRewardPayees::class)
        ->callTableAction('reject', $second->getKey(), data: ['reason' => 'Name mismatch.'])
        ->assertHasNoErrors();

    expect($second->refresh()->status)->toBe(RewardPayeeStatus::Rejected);
});
