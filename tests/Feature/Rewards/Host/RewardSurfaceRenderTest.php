<?php

declare(strict_types=1);

use AIArmada\FilamentRewards\Resources\RewardBenefitGrantResource\Pages\ListRewardBenefitGrants;
use AIArmada\FilamentRewards\Resources\RewardBenefitItemResource\Pages\ListRewardBenefitItems;
use AIArmada\FilamentRewards\Resources\RewardFraudResource\Pages\ListRewardFraudCases;
use AIArmada\FilamentRewards\Resources\RewardFundingResource\Pages\ListRewardFunding;
use AIArmada\FilamentRewards\Resources\RewardPayeeResource\Pages\ListRewardPayees;
use AIArmada\FilamentRewards\Resources\RewardPayoutResource\Pages\ListRewardPayouts;
use AIArmada\FilamentRewards\Resources\RewardRecoveryResource\Pages\ListRewardRecoveries;
use AIArmada\Rewards\Actions\CreateRewardBenefitItemAction;
use AIArmada\Rewards\Actions\CreditRewardPointsAction;
use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Actions\RecordRewardFundingAction;
use AIArmada\Rewards\Actions\RecordRewardFundingSourceAction;
use AIArmada\Rewards\Actions\TransitionRewardProgramStatusAction;
use AIArmada\Rewards\Actions\VerifyRewardPayeeAction;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;

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

function renderSweepSeed(string $key): array
{
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram($key, RewardProgramMode::Points);

    $renderFunder = (string) Str::uuid();

    RecordRewardFundingSourceAction::run(
        'TREASURY-render-'.$key, 'MYR', 50000, 'user', $renderFunder, 'staff',
        null, CarbonImmutable::parse('2026-06-02T00:00:00Z'),
    );

    RecordRewardFundingAction::run(
        $program, 'MYR', 50000, 'TREASURY-render-'.$key, 'user', $renderFunder, 'staff',
        null, CarbonImmutable::parse('2026-06-02T00:00:00Z'),
    );

    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    $member = User::factory()->create();
    $memberId = (string) $member->getKey();

    EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    CreditRewardPointsAction::run(
        'user', $memberId, $program->family_key, 'pts', 500,
        CarbonImmutable::parse('2026-06-11T00:00:00Z'), null,
        null, (string) $program->getKey(), 'seed:render-'.$key.'-'.$memberId,
        'system', null, 'test-seed', CarbonImmutable::parse('2026-06-11T00:00:00Z'),
    );

    CreateRewardBenefitItemAction::run(
        $program, 'render-seat', 'Render seat', ['Good standing'], 200, 'pts', false, 5,
        'user', (string) Str::uuid(), 'staff',
    );

    VerifyRewardPayeeAction::run(
        'user', $memberId, 'bank_transfer', 'ACCT-RENDER', 'Bank ••00R',
        'user', (string) Str::uuid(), 'staff', null, CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    return [$member];
}

function renderSweepGet(object $test, string $url): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $test->get($url);
    $queries = count(DB::getQueryLog());

    DB::disableQueryLog();

    return [$response, $queries];
}

it('renders member rewards and finance admin surfaces within query budgets', function (): void {
    [$member] = renderSweepSeed('render');

    $this->actingAs($member);
    [$memberResponse, $memberQueries] = renderSweepGet($this, '/dashboard/rewards');
    $memberResponse->assertOk()->assertSee('My rewards');
    fwrite(STDERR, "\n[render-sweep] member page queries: {$memberQueries}\n");
    expect($memberQueries)->toBeLessThan(60);

    $this->actingAs($this->staff);

    $pages = [
        'funding' => ListRewardFunding::getUrl(),
        'payees' => ListRewardPayees::getUrl(),
        'payouts' => ListRewardPayouts::getUrl(),
        'benefit-items' => ListRewardBenefitItems::getUrl(),
        'benefit-grants' => ListRewardBenefitGrants::getUrl(),
        'recoveries' => ListRewardRecoveries::getUrl(),
    ];

    foreach ($pages as $name => $url) {
        [$response, $queries] = renderSweepGet($this, $url);
        $response->assertOk();
        fwrite(STDERR, "[render-sweep] admin {$name} queries: {$queries}\n");
        expect($queries)->toBeLessThan(100);
    }

    $this->get(ListRewardFraudCases::getUrl())->assertForbidden();

    $steward = User::factory()->create();
    setPermissionsTeamId(null);
    $steward->assignRole('moderator');

    $this->actingAs($steward);
    [$fraudResponse, $fraudQueries] = renderSweepGet($this, ListRewardFraudCases::getUrl());
    $fraudResponse->assertOk();
    fwrite(STDERR, "[render-sweep] admin fraud queries: {$fraudQueries}\n");
    expect($fraudQueries)->toBeLessThan(100);

    $this->get('/api/v1/community/release')->assertOk();
})->group('render-sweep');
