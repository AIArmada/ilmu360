<?php

declare(strict_types=1);

use AIArmada\Affiliates\Models\AffiliateFraudSignal;
use App\Actions\Rewards\EnrollRewardMemberAction;
use App\Actions\Rewards\OpenRewardAppealAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Enums\Rewards\RewardAppealStatus;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardProgramStatus;
use App\Filament\Resources\RewardAppeals\Pages\ListRewardAppeals;
use App\Filament\Resources\RewardAwards\Pages\ListRewardAwards;
use App\Filament\Resources\RewardPrograms\Pages\ViewRewardProgram;
use App\Jobs\Rewards\EvaluateRewardEntitlementJob;
use App\Models\RewardAppeal;
use App\Models\RewardAward;
use App\Models\RewardProgramVersion;
use App\Models\User;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Rewards\Host\Support\RewardHostFixtures;
use Tests\Feature\Rewards\Ledgers\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setPermissionsTeamId(null);
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->staff = User::factory()->create();
    setPermissionsTeamId(null);
    $this->staff->assignRole('admin');
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function adminHeldAward(User $staff, bool $suspect = false): RewardAward
{
    $sharer = User::factory()->create();
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram(
        'admin-award', RewardProgramMode::Recognition,
        $suspect ? [] : ['holds' => ['manual_review_outcomes' => ['signup']]],
    );
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);
    EnrollRewardMemberAction::run($program->refresh(), 'user', (string) $sharer->getKey(), $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'));

    $actor = User::factory()->create();
    $key = ShareOutcomeBusinessKey::signup((string) $actor->getKey());
    RewardHostFixtures::receipt($key, 'user:'.$actor->getKey(), [
        'attribution_id' => (string) Str::uuid(),
        'outcome_type' => 'signup',
        'occurred_at' => CarbonImmutable::now('UTC')->subMinutes(5),
    ]);
    $conversion = RewardHostFixtures::conversion($key, [
        'sharer_user_id' => (string) $sharer->getKey(),
        'actor_user_id' => (string) $actor->getKey(),
        'occurred_at' => CarbonImmutable::now('UTC')->subMinutes(5),
    ]);

    if ($suspect) {
        AffiliateFraudSignal::query()->create([
            'affiliate_id' => (string) $conversion->getAttribute('affiliate_id'),
            'conversion_id' => (string) $conversion->getKey(),
            'rule_code' => 'ADMIN-SUSPECT',
            'risk_points' => 10,
            'severity' => 'medium',
            'description' => 'admin test suspect signal',
            'status' => 'detected',
            'detected_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    EvaluateRewardEntitlementJob::dispatchSync($key);

    return RewardAward::query()
        ->where('program_id', $program->getKey())
        ->where('recipient_id', (string) $sharer->getKey())
        ->firstOrFail();
}

it('lets staff approve a held award from the awards table', function (): void {
    $award = adminHeldAward($this->staff);
    expect($award->status)->toBe(RewardAwardStatus::Held);

    Livewire::actingAs($this->staff)
        ->test(ListRewardAwards::class)
        ->assertCanSeeTableRecords([$award])
        ->callTableAction('approve', $award->getKey(), data: [
            'reason' => 'Verified contribution; releasing the hold.',
        ])
        ->assertHasNoTableActionErrors();

    expect($award->fresh()->status)->toBe(RewardAwardStatus::Approved);
});

it('lets staff dismiss fraud without approving, and reverse approved awards', function (): void {
    $suspect = adminHeldAward($this->staff, true);
    expect($suspect->status)->toBe(RewardAwardStatus::Held);

    Livewire::actingAs($this->staff)
        ->test(ListRewardAwards::class)
        ->callTableAction('dismiss_fraud', $suspect->getKey(), data: [
            'reason' => 'Signal investigated; no fraud found.',
        ])
        ->assertHasNoTableActionErrors();

    expect($suspect->fresh()->status)->toBe(RewardAwardStatus::Held);

    // Dismissal is not approval: the still-suspect source blocks approval.
    Livewire::actingAs($this->staff)
        ->test(ListRewardAwards::class)
        ->callTableAction('approve', $suspect->getKey(), data: [
            'reason' => 'Attempted approval while suspect.',
        ])
        ->assertHasTableActionErrors();

    expect($suspect->fresh()->status)->toBe(RewardAwardStatus::Held);

    $award = adminHeldAward($this->staff);

    Livewire::actingAs($this->staff)
        ->test(ListRewardAwards::class)
        ->callTableAction('approve', $award->getKey(), data: [
            'reason' => 'Approved after manual review.',
        ])
        ->assertHasNoTableActionErrors();

    expect($award->fresh()->status)->toBe(RewardAwardStatus::Approved);

    Livewire::actingAs($this->staff)
        ->test(ListRewardAwards::class)
        ->callTableAction('reverse', $award->getKey(), data: [
            'reason' => 'Source withdrawn after approval.',
        ])
        ->assertHasNoTableActionErrors();

    expect($award->fresh()->status)->toBe(RewardAwardStatus::Reversed);
});

it('lets staff publish a version and transition a program', function (): void {
    [$program] = RewardLedgerFixtures::seedVersionedProgram('admin-program', RewardProgramMode::Recognition);
    $program = $program->refresh();
    expect($program->status)->toBe(RewardProgramStatus::Draft);

    Livewire::actingAs($this->staff)
        ->test(ViewRewardProgram::class, ['record' => $program->getKey()])
        ->callAction('publish_version', data: [
            'effective_from' => CarbonImmutable::now('UTC')->addHour()->toIso8601String(),
            'recognition_template' => 'quiet-thanks',
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
            'terms_version_label' => 'v2',
            'terms_body' => 'Earn only on verified eligible outcomes.',
            'terms_earn_basis' => 'Verified eligible outcomes only.',
            'terms_changes' => 'Changes apply prospectively after reacceptance.',
            'terms_no_ranking' => 'No public ranking.',
            'reason' => 'Second version for admin coverage.',
        ])
        ->assertHasNoActionErrors();

    expect(RewardProgramVersion::query()->where('program_id', $program->getKey())->count())->toBe(2);

    Livewire::actingAs($this->staff)
        ->test(ViewRewardProgram::class, ['record' => $program->getKey()])
        ->callAction('transition_status', data: ['to' => RewardProgramStatus::Ready->value])
        ->assertHasNoActionErrors();

    expect($program->fresh()->status)->toBe(RewardProgramStatus::Ready);
});

it('lets staff decide an appeal from the appeals table', function (): void {
    $award = adminHeldAward($this->staff);

    $appeal = OpenRewardAppealAction::run(
        $award, 'user', (string) $award->recipient_id, 'member',
        'Please recheck my held award.',
    );
    expect($appeal->status)->toBe(RewardAppealStatus::Open);

    Livewire::actingAs($this->staff)
        ->test(ListRewardAppeals::class)
        ->assertCanSeeTableRecords([$appeal])
        ->callTableAction('decide', $appeal->getKey(), data: [
            'to' => RewardAppealStatus::InReview->value,
            'reason' => 'Reviewing the held evidence.',
        ])
        ->assertHasNoTableActionErrors();

    expect($appeal->fresh()->status)->toBe(RewardAppealStatus::InReview);
    expect(RewardAppeal::query()->whereKey($appeal->getKey())->value('status'))->toBe(RewardAppealStatus::InReview);
});
