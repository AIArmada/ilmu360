<?php

declare(strict_types=1);

use App\Actions\Rewards\PublishRewardProgramVersionAction;
use App\Actions\Rewards\TransitionRewardAwardAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardProgramStatus;
use App\Jobs\Rewards\EvaluateRewardEntitlementJob;
use App\Models\RewardAward;
use App\Models\RewardProgram;
use App\Models\User;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Rewards\Host\Support\RewardHostFixtures;
use Tests\Feature\Rewards\Ledgers\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setPermissionsTeamId(null);
    $this->seed(RoleSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->member = User::factory()->create();
});

function memberApiActingAs(User $user): void
{
    Sanctum::actingAs($user);
}

/**
 * @return array{0: RewardProgram, 1: mixed}
 */
function memberApiProgram(string $prefix, RewardProgramMode $mode = RewardProgramMode::Recognition): array
{
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram($prefix, $mode);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    return [$program->refresh(), $version];
}

function memberApiAward(RewardProgram $program, User $sharer): RewardAward
{
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

it('requires authentication for every rewards endpoint', function (): void {
    $programId = (string) Str::uuid();
    $awardId = (string) Str::uuid();
    $appealId = (string) Str::uuid();

    test()->getJson('/api/v1/rewards/programs')->assertUnauthorized();
    test()->getJson('/api/v1/rewards/programs/'.$programId)->assertUnauthorized();
    test()->postJson('/api/v1/rewards/programs/'.$programId.'/enrollments', [])->assertUnauthorized();
    test()->deleteJson('/api/v1/rewards/programs/'.$programId.'/enrollments')->assertUnauthorized();
    test()->getJson('/api/v1/rewards/awards')->assertUnauthorized();
    test()->getJson('/api/v1/rewards/awards/'.$awardId)->assertUnauthorized();
    test()->getJson('/api/v1/rewards/balances')->assertUnauthorized();
    test()->getJson('/api/v1/rewards/badges')->assertUnauthorized();
    test()->getJson('/api/v1/rewards/appeals')->assertUnauthorized();
    test()->postJson('/api/v1/rewards/awards/'.$awardId.'/appeals', [])->assertUnauthorized();
    test()->getJson('/api/v1/rewards/appeals/'.$appealId)->assertUnauthorized();
    test()->getJson('/api/v1/rewards/awards/'.$awardId.'/payout')->assertUnauthorized();
    test()->getJson('/api/v1/rewards/payouts')->assertUnauthorized();
    test()->getJson('/api/v1/rewards/payees')->assertUnauthorized();
    test()->postJson('/api/v1/rewards/payees', [])->assertUnauthorized();
    test()->getJson('/api/v1/rewards/benefits/catalog')->assertUnauthorized();
    test()->postJson('/api/v1/rewards/benefits/redemptions', [])->assertUnauthorized();
    test()->getJson('/api/v1/rewards/benefits/grants')->assertUnauthorized();
    test()->getJson('/api/v1/rewards/impact')->assertUnauthorized();
    test()->getJson('/api/v1/rewards/cohort')->assertUnauthorized();
});

it('runs the catalog, enroll, show, reaccept, and leave journey', function (): void {
    memberApiActingAs($this->member);
    [$program, $version] = memberApiProgram('memberapi-journey');

    test()->getJson('/api/v1/rewards/programs')
        ->assertOk()
        ->assertJsonFragment(['id' => (string) $program->getKey(), 'enrolled' => false]);

    test()->getJson('/api/v1/rewards/programs/'.$program->getKey())
        ->assertOk()
        ->assertJsonPath('data.family_key', $program->family_key)
        ->assertJsonPath('data.enrollment', null);

    test()->postJson('/api/v1/rewards/programs/'.$program->getKey().'/enrollments', [
        'version_id' => (string) $version->getKey(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'active');

    test()->getJson('/api/v1/rewards/programs/'.$program->getKey())
        ->assertOk()
        ->assertJsonPath('data.enrollment.status', 'active')
        ->assertJsonPath('data.enrollment.acceptance.current', true);

    // A material terms change requires prospective reacceptance.
    $terms = RewardLedgerFixtures::terms(RewardProgramMode::Recognition);
    $terms['material']['earn_basis'] = 'Revised earn basis for reacceptance coverage.';
    $now = CarbonImmutable::now('UTC');
    $v2 = PublishRewardProgramVersionAction::run(
        $program, $now, RewardLedgerFixtures::rules(RewardProgramMode::Recognition), $terms, 'material terms revision', null, null, $now,
    );

    test()->getJson('/api/v1/rewards/programs/'.$program->getKey())
        ->assertOk()
        ->assertJsonPath('data.enrollment.acceptance.current', false);

    test()->postJson('/api/v1/rewards/programs/'.$program->getKey().'/reaccept', [
        'version_id' => (string) $v2->getKey(),
    ])->assertCreated();

    test()->deleteJson('/api/v1/rewards/programs/'.$program->getKey().'/enrollments')
        ->assertOk()
        ->assertJsonPath('data.status', 'left');

    // Earned history stays readable after leaving.
    test()->getJson('/api/v1/rewards/programs/'.$program->getKey())->assertOk();
});

it('rejects enrollment in non-active programs', function (): void {
    memberApiActingAs($this->member);
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram('memberapi-draft', RewardProgramMode::Recognition);

    test()->postJson('/api/v1/rewards/programs/'.$program->getKey().'/enrollments', [
        'version_id' => (string) $version->getKey(),
    ])->assertForbidden();

    test()->getJson('/api/v1/rewards/programs/'.$program->getKey())->assertForbidden();
});

it('shows redacted award history without counterpart identity', function (): void {
    memberApiActingAs($this->member);
    [$program] = memberApiProgram('memberapi-awards');
    $member = $this->member;

    test()->postJson('/api/v1/rewards/programs/'.$program->getKey().'/enrollments', [
        'version_id' => (string) $program->current_version_id,
    ])->assertCreated();

    $award = memberApiAward($program, $member);

    $index = test()->getJson('/api/v1/rewards/awards')->assertOk();
    $index->assertJsonFragment(['id' => (string) $award->getKey()]);

    $show = test()->getJson('/api/v1/rewards/awards/'.$award->getKey())->assertOk();
    $payload = $show->json('data');

    expect($payload['component'])->toBe('recognition');
    expect($payload['source']['outcome'])->toBe('signup');
    expect($payload['source']['outcome_label'])->toBe('New member signup');
    expect($payload['source']['source_date'])->toBe(CarbonImmutable::now('UTC')->toDateString());

    $raw = $show->getContent();
    expect($raw)->not->toContain('actor_id')
        ->and($raw)->not->toContain('sharer_id')
        ->and($raw)->not->toContain('source_business_key')
        ->and($raw)->not->toContain('attribution_id')
        ->and($raw)->not->toContain('metadata');

    // Awards carry decision reasons without staff actor identity.
    expect($payload['reasons'])->not->toBeEmpty();
    expect($payload['reasons'][0])->toHaveKey('decided_by');
    expect($payload['reasons'][0])->not->toHaveKey('actor_id');
});

it('hides other members awards and appeals', function (): void {
    memberApiActingAs($this->member);
    [$program] = memberApiProgram('memberapi-privacy');

    $stranger = User::factory()->create();
    Sanctum::actingAs($stranger);
    test()->postJson('/api/v1/rewards/programs/'.$program->getKey().'/enrollments', [
        'version_id' => (string) $program->current_version_id,
    ])->assertCreated();
    $award = memberApiAward($program, $stranger);

    Sanctum::actingAs($this->member);
    test()->getJson('/api/v1/rewards/awards/'.$award->getKey())->assertNotFound();
    test()->getJson('/api/v1/rewards/awards')->assertOk()->assertJsonMissing(['id' => (string) $award->getKey()]);
    test()->postJson('/api/v1/rewards/awards/'.$award->getKey().'/appeals', ['reason' => 'Not mine.'])->assertNotFound();
});

it('opens and tracks an appeal on an own award', function (): void {
    memberApiActingAs($this->member);
    [$program] = memberApiProgram('memberapi-appeal');

    test()->postJson('/api/v1/rewards/programs/'.$program->getKey().'/enrollments', [
        'version_id' => (string) $program->current_version_id,
    ])->assertCreated();

    $award = memberApiAward($program, $this->member);

    $opened = test()->postJson('/api/v1/rewards/awards/'.$award->getKey().'/appeals', [
        'reason' => 'The outcome date looks wrong on my record.',
    ])->assertCreated();
    $appealId = $opened->json('data.id');

    test()->getJson('/api/v1/rewards/appeals')->assertOk()->assertJsonFragment(['id' => $appealId]);
    test()->getJson('/api/v1/rewards/appeals/'.$appealId)
        ->assertOk()
        ->assertJsonPath('data.status', 'open');
});

it('reports point balances only after fulfillment', function (): void {
    memberApiActingAs($this->member);
    [$program] = memberApiProgram('memberapi-points', RewardProgramMode::Points);

    test()->postJson('/api/v1/rewards/programs/'.$program->getKey().'/enrollments', [
        'version_id' => (string) $program->current_version_id,
    ])->assertCreated();

    test()->getJson('/api/v1/rewards/balances')->assertOk()->assertJson(['data' => []]);

    $award = memberApiAward($program, $this->member);
    expect($award->status)->toBe(RewardAwardStatus::Approved);

    $staff = User::factory()->create();
    TransitionRewardAwardAction::run($award, RewardAwardStatus::Fulfilled, 'user', (string) $staff->getKey(), 'staff', 'member-api fulfillment');

    $balances = test()->getJson('/api/v1/rewards/balances')->assertOk();
    $balances->assertJsonFragment(['family_key' => $program->family_key, 'unit' => 'pts']);
    expect($balances->json('data.0.buckets.available'))->toBe(10);
    expect($balances->json('data.0.buckets.pending'))->toBe(0);

    test()->getJson('/api/v1/rewards/badges')->assertOk()->assertJson(['data' => []]);
});
