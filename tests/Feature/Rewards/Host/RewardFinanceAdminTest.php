<?php

declare(strict_types=1);

use App\Actions\Rewards\CreateRewardBenefitItemAction;
use App\Actions\Rewards\CreditRewardPointsAction;
use App\Actions\Rewards\EnrollRewardMemberAction;
use App\Actions\Rewards\EvaluateRewardEntitlementAction;
use App\Actions\Rewards\FulfillRewardBenefitAction;
use App\Actions\Rewards\RecordRewardFundingAction;
use App\Actions\Rewards\RecordRewardFundingSourceAction;
use App\Actions\Rewards\RedeemRewardBenefitAction;
use App\Actions\Rewards\RequestRewardPayoutAction;
use App\Actions\Rewards\TransitionRewardAwardAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Actions\Rewards\VerifyRewardPayeeAction;
use App\Contracts\Rewards\RewardFinancialReadinessChecker;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardProgramStatus;
use App\Filament\Resources\RewardBenefitGrants\Pages\ListRewardBenefitGrants;
use App\Filament\Resources\RewardBenefitItems\Pages\ListRewardBenefitItems;
use App\Filament\Resources\RewardFraud\Pages\ListRewardFraudCases;
use App\Filament\Resources\RewardFunding\Pages\ListRewardFunding;
use App\Filament\Resources\RewardPayees\Pages\ListRewardPayees;
use App\Filament\Resources\RewardPayouts\Pages\ListRewardPayouts;
use App\Models\RewardAward;
use App\Models\RewardBenefitItem;
use App\Models\RewardFundingEntry;
use App\Models\RewardPayee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Rewards\Ledgers\Support\FakeRewardSourceFactReader;
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

function financeAdminPayoutSetup(User $staff): array
{
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram('admin-fin', RewardProgramMode::Cash);
    $funder = (string) Str::uuid();
    $verifier = (string) Str::uuid();
    $requester = (string) Str::uuid();

    RecordRewardFundingSourceAction::run(
        'TREASURY-admin-fin', 'MYR', 10000, 'user', $funder, 'staff',
        null, CarbonImmutable::parse('2026-06-02T00:00:00Z'),
    );

    RecordRewardFundingAction::run(
        $program, 'MYR', 10000, 'TREASURY-admin-fin', 'user', $funder, 'staff',
        null, CarbonImmutable::parse('2026-06-02T00:00:00Z'),
    );

    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    $memberId = (string) Str::uuid();

    EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-admin-fin-1', $memberId));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, RewardComponent::Cash, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        'src-admin-fin-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $payee = VerifyRewardPayeeAction::run(
        'user', $memberId, 'bank_transfer', 'ACCT-A', 'Bank ••00A',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $approved = (new TransitionRewardAwardAction(app(RewardFinancialReadinessChecker::class), $reader))->handle(
        $result->award, RewardAwardStatus::Approved, 'user', (string) $staff->getKey(), 'staff', 'policy-ok',
        null, null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );

    $request = RequestRewardPayoutAction::run(
        $approved, $payee, 'bank_transfer', 'program', 'user', $requester, 'staff',
        null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );

    return [$program, $request];
}

it('renders finance tables and runs record, verify, and approve actions', function (): void {
    [$program] = RewardLedgerFixtures::seedVersionedProgram('admin-fin-ui', RewardProgramMode::Cash);

    Livewire::actingAs($this->staff)
        ->test(ListRewardFunding::class)
        ->callAction('record_source', data: [
            'source_ref' => 'TREASURY-ui-1',
            'currency' => 'MYR',
            'amount_minor' => 5000,
            'evidence_note' => 'ui test',
        ])
        ->assertHasNoActionErrors()
        ->callAction('record', data: [
            'program_id' => (string) $program->getKey(),
            'currency' => 'MYR',
            'amount_minor' => 5000,
            'source_ref' => 'TREASURY-ui-1',
            'evidence_note' => 'ui test',
        ])
        ->assertHasNoActionErrors();

    expect(RewardFundingEntry::query()->where('source_ref', 'TREASURY-ui-1')->count())->toBe(1);

    $memberId = (string) Str::uuid();

    Livewire::actingAs($this->staff)
        ->test(ListRewardPayees::class)
        ->callAction('verify', data: [
            'recipient_id' => $memberId,
            'method' => 'bank_transfer',
            'account_ref' => 'ACCT-UI',
            'account_display' => 'Bank ••00U',
            'evidence_note' => 'ui test',
        ])
        ->assertHasNoActionErrors();

    expect(RewardPayee::query()->where('recipient_id', $memberId)->count())->toBe(1);

    [, $request] = financeAdminPayoutSetup($this->staff);

    Livewire::actingAs($this->staff)
        ->test(ListRewardPayouts::class)
        ->assertCanSeeTableRecords([$request])
        ->callTableAction('approve', $request->getKey(), data: [
            'reason' => 'Finance review passed; releasing to the bank queue.',
        ])
        ->assertHasNoTableActionErrors();

    expect($request->fresh()->status->value)->toBe('approved');
});

it('renders benefit catalog and fraud stewardship tables', function (): void {
    [$program] = RewardLedgerFixtures::seedVersionedProgram('admin-ben-ui', RewardProgramMode::Points);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    Livewire::actingAs($this->staff)
        ->test(ListRewardBenefitItems::class)
        ->callAction('create', data: [
            'program_id' => (string) $program->getKey(),
            'benefit_key' => 'ui-seat',
            'title' => 'UI seat',
            'conditions' => "Good standing\nOne per member",
            'cost_points' => 100,
            'cost_unit' => 'pts',
            'direct_entitlement' => false,
            'capacity' => 10,
            'expires_at' => null,
        ])
        ->assertHasNoActionErrors();

    // Fraud stewardship is separately authorized: admins (award
    // approvers) are forbidden; moderators (stewards) may enter.
    Livewire::actingAs($this->staff)
        ->test(ListRewardFraudCases::class)
        ->assertForbidden();

    $steward = User::factory()->create();
    setPermissionsTeamId(null);
    $steward->assignRole('moderator');

    Livewire::actingAs($steward)
        ->test(ListRewardFraudCases::class)
        ->assertOk();

    expect(RewardAward::query()->count())->toBe(0);
});

it('creates coupon-configured items and records recalls without table edits', function (): void {
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram('admin-ben-coupon', RewardProgramMode::Points);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    Livewire::actingAs($this->staff)
        ->test(ListRewardBenefitItems::class)
        ->callAction('create', data: [
            'program_id' => (string) $program->getKey(),
            'benefit_key' => 'ui-coupon',
            'title' => 'UI coupon',
            'conditions' => 'Good standing',
            'cost_points' => 100,
            'cost_unit' => 'pts',
            'direct_entitlement' => false,
            'capacity' => 10,
            'expires_at' => null,
            'coupon_enabled' => true,
            'coupon_value' => 1500,
            'coupon_currency' => 'MYR',
        ])
        ->assertHasNoActionErrors();

    $item = RewardBenefitItem::query()->where('benefit_key', 'ui-coupon')->firstOrFail();

    expect($item->fulfillment_config)->toBe(['voucher' => ['type' => 'fixed', 'value' => 1500, 'currency' => 'MYR']]);

    $member = User::factory()->create();
    $memberId = (string) $member->getKey();

    EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    CreditRewardPointsAction::run(
        'user', $memberId, $program->family_key, 'pts', 500,
        CarbonImmutable::parse('2026-06-11T00:00:00Z'), null,
        null, (string) $program->getKey(), 'seed:admin-coupon-'.$memberId,
        'system', null, 'test-seed', CarbonImmutable::parse('2026-06-11T00:00:00Z'),
    );

    $manual = CreateRewardBenefitItemAction::run(
        $program, 'ui-manual', 'UI manual', ['Good standing'], 100, 'pts', false, 4,
        'user', (string) $this->staff->getKey(), 'admin',
    );

    $grant = RedeemRewardBenefitAction::run(
        'user', $memberId, $program->family_key, $manual, 1, 'admin-recall-1', 'manual',
        'user', $memberId, 'member', null, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $issued = FulfillRewardBenefitAction::run(
        $grant, ['handoff_reference' => 'DESK-UI'], 'user', (string) $this->staff->getKey(), 'admin',
        CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    Livewire::actingAs($this->staff)
        ->test(ListRewardBenefitGrants::class)
        ->assertCanSeeTableRecords([$issued])
        ->callTableAction('record_recall', $issued->getKey(), data: [
            'recall_reference' => 'RECALL-UI-1',
        ])
        ->assertHasNoTableActionErrors()
        ->callTableAction('revoke', $issued->getKey(), data: [
            'reason' => 'Recalled at the desk; restoring the member.',
        ])
        ->assertHasNoTableActionErrors();

    expect($issued->refresh()->status->value)->toBe('revoked')
        ->and($issued->evidence['recall_reference'])->toBe('RECALL-UI-1');
});
