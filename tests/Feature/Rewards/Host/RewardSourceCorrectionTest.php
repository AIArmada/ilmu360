<?php

declare(strict_types=1);

use AIArmada\FilamentRewards\Resources\RewardAwardResource\Pages\ListRewardAwards;
use AIArmada\Rewards\Actions\ApplyRewardSourceCorrectionsAction;
use AIArmada\Rewards\Actions\ApproveRewardPayoutAction;
use AIArmada\Rewards\Actions\ConfirmRewardPayoutAction;
use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Actions\EvaluateRewardEntitlementAction;
use AIArmada\Rewards\Actions\RecordRewardFundingAction;
use AIArmada\Rewards\Actions\RecordRewardFundingSourceAction;
use AIArmada\Rewards\Actions\RequestRewardPayoutAction;
use AIArmada\Rewards\Actions\SubmitRewardPayoutAction;
use AIArmada\Rewards\Actions\TransitionRewardAwardAction;
use AIArmada\Rewards\Actions\TransitionRewardProgramStatusAction;
use AIArmada\Rewards\Actions\VerifyRewardPayeeAction;
use AIArmada\Rewards\Contracts\RewardFinancialReadinessChecker;
use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardComponent;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardRecovery;
use App\Models\ShareOutcomeCorrection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Rewards\Host\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('affiliates.payouts.reward_award_settlement.enabled', true);
    setPermissionsTeamId(null);
    $this->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function correctionStaff(string $role = 'admin'): User
{
    $staff = User::factory()->create();
    setPermissionsTeamId(null);
    $staff->assignRole($role);
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $staff;
}

/**
 * @return array{0: RewardAward, 1: string, 2: FakeRewardSourceFactReader}
 */
function correctionHeldAward(string $prefix, string $source, RewardProgramMode $mode = RewardProgramMode::Points): array
{
    if ($mode === RewardProgramMode::Cash) {
        [$program, $version] = RewardLedgerFixtures::seedVersionedProgram($prefix, $mode);
        $funder = (string) User::factory()->create()->getKey();

        RecordRewardFundingSourceAction::run(
            'TREASURY-'.$prefix, 'MYR', 100000, 'user', $funder, 'staff',
            ['document' => 'treasury-1'], CarbonImmutable::parse('2026-06-02T00:00:00Z'),
        );
        RecordRewardFundingAction::run(
            $program, 'MYR', 100000, 'TREASURY-'.$prefix, 'user', $funder, 'staff',
            ['document' => 'treasury-1'], CarbonImmutable::parse('2026-06-02T00:00:00Z'),
        );
        TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
        $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);
    } else {
        [$program, $version] = RewardLedgerFixtures::seedActiveEnrolled($prefix, $mode);
    }

    $memberId = (string) User::factory()->create()->getKey();

    EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version,
        'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact($source, $memberId));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program, $mode === RewardProgramMode::Cash ? RewardComponent::Cash : RewardComponent::Points,
        'user', $memberId, RewardBeneficiaryRole::Recipient,
        $source, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    return [$result->award, $memberId, $reader];
}

function correctionRow(string $businessKey, string $reason = 'event-withdrawn'): ShareOutcomeCorrection
{
    return ShareOutcomeCorrection::query()->create([
        'conversion_id' => (string) Str::uuid(),
        'business_key' => $businessKey,
        'reason' => $reason,
        'occurred_at' => CarbonImmutable::parse('2026-06-18T00:00:00Z'),
    ]);
}

function correctionApprove(RewardAward $award, $reader, string $staff, string $at = '2026-06-16T00:00:00Z'): RewardAward
{
    return (new TransitionRewardAwardAction(app(RewardFinancialReadinessChecker::class), $reader))->handle(
        $award, RewardAwardStatus::Approved, 'user', $staff, 'staff', 'policy-ok', null, null, CarbonImmutable::parse($at),
    );
}

it('reverses live awards for corrected sources and reports every outcome', function () {
    $staff = correctionStaff();
    $staffId = (string) $staff->getKey();

    [$held] = correctionHeldAward('corr-sweep-1', 'src-corr-held-1', RewardProgramMode::Cash);
    [$approved] = correctionHeldAward('corr-sweep-2', 'src-corr-appr-1');

    expect($held->status)->toBe(RewardAwardStatus::Held)
        ->and($approved->status)->toBe(RewardAwardStatus::Approved);

    // Already-terminal awards settle as skipped, never reprocessed.
    [$settled] = correctionHeldAward('corr-sweep-3', 'src-corr-term-1', RewardProgramMode::Cash);
    (new TransitionRewardAwardAction(null, new FakeRewardSourceFactReader))->handle(
        $settled, RewardAwardStatus::Rejected, 'user', $staffId, 'staff', 'ineligible', null, null,
        CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    // Hold the queued recheck so the sweep itself does the work.
    Queue::fake();

    correctionRow('src-corr-held-1');
    correctionRow('src-corr-appr-1');
    correctionRow('src-corr-term-1');
    correctionRow('src-corr-orphan-1');

    $report = ApplyRewardSourceCorrectionsAction::run(
        'user', $staffId, 'staff', CarbonImmutable::parse('2026-06-19T00:00:00Z'),
    );

    expect($report['reversed'])->toHaveCount(1)
        ->and($report['rejected'])->toHaveCount(1)
        ->and($report['recoveries'])->toBe([])
        ->and($report['skipped'])->toHaveCount(1)
        ->and($report['errors'])->toBe([])
        ->and($held->refresh()->status)->toBe(RewardAwardStatus::Rejected)
        ->and($approved->refresh()->status)->toBe(RewardAwardStatus::Reversed)
        ->and($approved->entries()->where('to_status', 'reversed')->firstOrFail()->reason)->toContain('source-correction');

    // Reruns converge: everything already settled, nothing reprocessed.
    $again = ApplyRewardSourceCorrectionsAction::run(
        'user', $staffId, 'staff', CarbonImmutable::parse('2026-06-19T00:00:00Z'),
    );

    expect($again['reversed'])->toBe([])
        ->and($again['rejected'])->toBe([])
        ->and($again['skipped'])->toHaveCount(3)
        ->and($again['errors'])->toBe([]);
});

it('composes with the queued recheck without double-processing', function () {
    $staff = correctionStaff();
    $staffId = (string) $staff->getKey();

    [$held] = correctionHeldAward('corr-job-1', 'src-corr-job-1', RewardProgramMode::Cash);
    [$approved] = correctionHeldAward('corr-job-2', 'src-corr-job-2');

    // Sync queue: the observer's recheck job settles inline on commit.
    correctionRow('src-corr-job-1');
    correctionRow('src-corr-job-2');

    expect($held->refresh()->status)->toBe(RewardAwardStatus::Rejected)
        ->and($approved->refresh()->status)->toBe(RewardAwardStatus::Reversed);

    // The sweep then finds everything settled and reports skips.
    $report = ApplyRewardSourceCorrectionsAction::run(
        'user', $staffId, 'staff', CarbonImmutable::parse('2026-06-19T00:00:00Z'),
    );

    expect($report['reversed'])->toBe([])
        ->and($report['rejected'])->toBe([])
        ->and($report['skipped'])->toHaveCount(2)
        ->and($report['errors'])->toBe([]);
});

it('reverses fulfilled cash through recovery instead of blind transition', function () {
    [$program] = RewardLedgerFixtures::seedVersionedProgram('corr-cash-1', RewardProgramMode::Cash);
    $staff = correctionStaff();
    $staffId = (string) $staff->getKey();

    RecordRewardFundingSourceAction::run(
        'TREASURY-corr-cash-1', 'MYR', 100000, 'user', $staffId, 'staff',
        ['document' => 'treasury-1'], CarbonImmutable::parse('2026-06-02T00:00:00Z'),
    );
    RecordRewardFundingAction::run(
        $program, 'MYR', 100000, 'TREASURY-corr-cash-1', 'user', $staffId, 'staff',
        ['document' => 'treasury-1'], CarbonImmutable::parse('2026-06-02T00:00:00Z'),
    );
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    $memberId = (string) User::factory()->create()->getKey();
    $version = $program->versions()->orderByDesc('seq')->firstOrFail();

    EnrollRewardMemberAction::run(
        $program->refresh(), 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    $reader = new FakeRewardSourceFactReader;
    $reader->seed(RewardLedgerFixtures::fact('src-corr-cash-1', $memberId));

    $result = (new EvaluateRewardEntitlementAction($reader))->handle(
        $program->refresh(), RewardComponent::Cash, 'user', $memberId, RewardBeneficiaryRole::Recipient,
        'src-corr-cash-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $verifier = (string) User::factory()->create()->getKey();
    $requester = (string) User::factory()->create()->getKey();
    $approver = (string) User::factory()->create()->getKey();

    $payee = VerifyRewardPayeeAction::run(
        'user', $memberId, 'bank_transfer', 'ACCT-CORR', 'Bank ••00CO',
        'user', $verifier, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $award = correctionApprove($result->award, $reader, $staffId);

    $request = RequestRewardPayoutAction::run(
        $award, $payee, 'bank_transfer', 'program', 'user', $requester, 'staff',
        null, CarbonImmutable::parse('2026-06-17T00:00:00Z'),
    );
    $request = ApproveRewardPayoutAction::run(
        $request, 'user', $approver, 'staff', 'finance-ok', CarbonImmutable::parse('2026-06-17T01:00:00Z'),
    );
    $request = SubmitRewardPayoutAction::run(
        $request, 'user', $staffId, 'staff', CarbonImmutable::parse('2026-06-17T02:00:00Z'),
    );
    ConfirmRewardPayoutAction::run(
        $request, ['bank_reference' => 'BANK-CO-1', 'transferred_at' => '2026-06-17T03:00:00Z'],
        'user', $staffId, 'staff', CarbonImmutable::parse('2026-06-17T03:00:00Z'),
    );

    $award = (new TransitionRewardAwardAction(app(RewardFinancialReadinessChecker::class), $reader))->handle(
        $award->refresh(), RewardAwardStatus::Fulfilled, 'user', $staffId, 'staff', 'fulfill',
        null, null, CarbonImmutable::parse('2026-06-17T04:00:00Z'),
    );

    // Raw reversal of fulfilled cash refuses loudly — no silent skip.
    expect(fn () => (new TransitionRewardAwardAction(null, $reader))->handle(
        $award->refresh(), RewardAwardStatus::Reversed, 'user', $staffId, 'staff', 'blind',
        null, null, CarbonImmutable::parse('2026-06-19T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'fulfillment connector');

    correctionRow('src-corr-cash-1');

    $report = ApplyRewardSourceCorrectionsAction::run(
        'user', $staffId, 'staff', CarbonImmutable::parse('2026-06-19T00:00:00Z'),
    );

    expect($report['reversed'])->toBe([(string) $award->getKey()])
        ->and($report['recoveries'])->toHaveCount(1)
        ->and($award->refresh()->status)->toBe(RewardAwardStatus::Reversed)
        ->and(RewardRecovery::query()->whereKey($report['recoveries'][0])->exists())->toBeTrue();
});

it('applies corrections through the operator command with truthful exits', function () {
    $staff = correctionStaff();

    [$held] = correctionHeldAward('corr-cmd-1', 'src-corr-cmd-1');
    Queue::fake();
    correctionRow('src-corr-cmd-1');

    expect(Artisan::call('rewards:apply-source-corrections', [
        '--actor' => (string) $staff->getKey(),
        '--actor-role' => 'staff',
        '--as-of' => '2026-06-19T00:00:00Z',
    ]))->toBe(0)
        ->and(Artisan::output())->toContain('1 reversed')
        ->and($held->refresh()->status)->toBe(RewardAwardStatus::Reversed);

    // System sweep without actor attribution still runs.
    [$held2] = correctionHeldAward('corr-cmd-2', 'src-corr-cmd-2');
    correctionRow('src-corr-cmd-2');

    expect(Artisan::call('rewards:apply-source-corrections', [
        '--as-of' => '2026-06-19T00:00:00Z',
    ]))->toBe(0)
        ->and($held2->refresh()->status)->toBe(RewardAwardStatus::Reversed);

    // Staff role without actor attribution is a usage error.
    expect(Artisan::call('rewards:apply-source-corrections', ['--actor-role' => 'staff']))->toBe(2);
});

it('reverses approved cash from the admin table', function () {
    $staff = correctionStaff();
    $staffId = (string) $staff->getKey();

    [$held, $adminMemberId, $adminReader] = correctionHeldAward('corr-admin-1', 'src-corr-admin-1', RewardProgramMode::Cash);

    VerifyRewardPayeeAction::run(
        'user', $adminMemberId, 'bank_transfer', 'ACCT-ADMIN', 'Bank ••00AD',
        'user', $staffId, 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $award = correctionApprove($held->refresh(), $adminReader, $staffId);

    Livewire::actingAs($staff)
        ->test(ListRewardAwards::class)
        ->callTableAction('reverse', $award->getKey(), data: ['reason' => 'duplicate payout review'])
        ->assertHasNoTableActionErrors();

    expect($award->refresh()->status)->toBe(RewardAwardStatus::Reversed);
});
