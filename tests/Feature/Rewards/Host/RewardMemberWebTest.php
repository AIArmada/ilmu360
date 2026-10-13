<?php

declare(strict_types=1);

use AIArmada\Rewards\Actions\CreateRewardBenefitItemAction;
use AIArmada\Rewards\Actions\CreditRewardPointsAction;
use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Actions\TransitionRewardProgramStatusAction;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Jobs\EvaluateRewardEntitlementJob;
use AIArmada\Rewards\Models\RewardAppeal;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardBenefitGrant;
use AIArmada\Rewards\Models\RewardEnrollment;
use AIArmada\Rewards\Models\RewardPayee;
use AIArmada\Rewards\Models\RewardProgram;
use App\Livewire\Pages\Dashboard\RewardMemberIndex;
use App\Models\User;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rewards\Host\Support\RewardHostFixtures;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

function webMemberProgram(string $prefix, RewardProgramMode $mode = RewardProgramMode::Points): array
{
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram($prefix, $mode);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    return [$program->refresh(), $version];
}

function webMemberAward(RewardProgram $program, mixed $version, User $sharer): RewardAward
{
    if (RewardEnrollment::query()->where('program_id', $program->getKey())->where('member_id', (string) $sharer->getKey())->doesntExist()) {
        EnrollRewardMemberAction::run($program, 'user', (string) $sharer->getKey(), $version, 'member-portal');
    }

    $actor = User::factory()->create();
    $key = ShareOutcomeBusinessKey::signup((string) $actor->getKey());
    RewardHostFixtures::receipt($key, 'user:'.$actor->getKey(), [
        'attribution_id' => (string) Str::uuid(),
        'outcome_type' => 'signup',
        'occurred_at' => CarbonImmutable::now('UTC')->subMinutes(5),
    ]);
    RewardHostFixtures::conversion($key, [
        'sharer_user_id' => (string) $sharer->getKey(),
        'actor_user_id' => (string) $actor->getKey(),
        'occurred_at' => CarbonImmutable::now('UTC')->subMinutes(5),
    ]);

    EvaluateRewardEntitlementJob::dispatchSync($key);

    return RewardAward::query()
        ->where('program_id', $program->getKey())
        ->where('recipient_id', (string) $sharer->getKey())
        ->orderByDesc('id')
        ->firstOrFail();
}

it('renders the member rewards page and redeems from the catalog', function () {
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram('web-member', RewardProgramMode::Points);
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
        null, (string) $program->getKey(), 'seed:web-member-'.$memberId,
        'system', null, 'test-seed', CarbonImmutable::parse('2026-06-11T00:00:00Z'),
    );

    $item = CreateRewardBenefitItemAction::run(
        $program, 'web-seat', 'Web seat', ['Good standing'], 200, 'pts', false, 5,
        'user', (string) Str::uuid(), 'staff',
    );

    Livewire::actingAs($member)
        ->test(RewardMemberIndex::class)
        ->assertOk()
        ->assertSee('My rewards')
        ->call('showTab', 'catalog')
        ->assertSee('Web seat')
        ->call('redeem', (string) $item->getKey())
        ->assertSee('reserved');

    expect(RewardBenefitGrant::query()->where('recipient_id', $memberId)->count())->toBe(1);
});

it('keeps balances complete when award history paginates past the first page', function () {
    [$programA, $versionA] = webMemberProgram('web-page-a');
    [$programB] = webMemberProgram('web-page-b');

    $member = User::factory()->create();
    $memberId = (string) $member->getKey();

    // Sixteen awards all in family A; family B holds lots but no awards.
    for ($i = 0; $i < 16; $i++) {
        webMemberAward($programA, $versionA, $member);
    }

    CreditRewardPointsAction::run(
        'user', $memberId, $programA->family_key, 'pts', 100,
        CarbonImmutable::now('UTC')->subDay(), null,
        null, (string) $programA->getKey(), 'seed:page-a-'.$memberId,
        'system', null, 'test-seed', CarbonImmutable::now('UTC')->subDay(),
    );
    CreditRewardPointsAction::run(
        'user', $memberId, $programB->family_key, 'pts', 250,
        CarbonImmutable::now('UTC')->subDay(), null,
        null, (string) $programB->getKey(), 'seed:page-b-'.$memberId,
        'system', null, 'test-seed', CarbonImmutable::now('UTC')->subDay(),
    );

    Livewire::actingAs($member)
        ->test(RewardMemberIndex::class)
        ->assertOk()
        ->assertSee('available 100')
        ->assertSee('available 250')
        ->call('gotoPage', 2, 'awards_page')
        ->assertOk()
        ->assertSee('available 100')
        ->assertSee('available 250');
});

it('enrolls, reaccepts, and leaves a program from the member page', function () {
    [$program] = webMemberProgram('web-enroll');

    $member = User::factory()->create();
    $memberId = (string) $member->getKey();

    Livewire::actingAs($member)
        ->test(RewardMemberIndex::class)
        ->call('showTab', 'programs')
        ->assertSee('Enroll')
        ->call('enroll', (string) $program->getKey())
        ->assertSee('Enrolled')
        ->set('leaveReason', 'taking a break')
        ->call('leave', (string) $program->getKey())
        ->assertSee('left the program');

    $enrollment = RewardEnrollment::query()
        ->where('program_id', $program->getKey())
        ->where('member_id', $memberId)
        ->firstOrFail();

    expect($enrollment->status->value)->toBe('left');
});

it('files an appeal and registers a payout destination from the member page', function () {
    [$program, $version] = webMemberProgram('web-appeal');
    $member = User::factory()->create();
    $award = webMemberAward($program, $version, $member);

    Livewire::actingAs($member)
        ->test(RewardMemberIndex::class)
        ->call('showTab', 'appeals')
        ->set('appealAwardId', (string) $award->getKey())
        ->set('appealReason', 'This share was attributed to me.')
        ->call('fileAppeal')
        ->assertSee('Appeal opened')
        ->call('showTab', 'payee')
        ->set('payeeMethod', 'manual')
        ->set('payeeRef', 'op-web-1')
        ->set('payeeDisplay', 'Desk Web 1')
        ->call('registerPayee')
        ->assertSee('submitted');

    expect(RewardAppeal::query()->where('award_id', $award->getKey())->count())->toBe(1)
        ->and(RewardPayee::query()->where('recipient_id', (string) $member->getKey())->count())->toBe(1);
});

it('renders statements and impact without leaking handles', function () {
    [$program, $version] = webMemberProgram('web-render');
    $member = User::factory()->create();
    webMemberAward($program, $version, $member);

    Livewire::actingAs($member)
        ->test(RewardMemberIndex::class)
        ->call('showTab', 'statements')
        ->assertOk()
        ->assertSee('Cash statements')
        ->call('showTab', 'impact')
        ->assertOk()
        ->assertSee('My impact');
});
