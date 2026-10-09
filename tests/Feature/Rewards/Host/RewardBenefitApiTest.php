<?php

declare(strict_types=1);

use App\Actions\Rewards\CreateRewardBenefitItemAction;
use App\Actions\Rewards\CreditRewardPointsAction;
use App\Actions\Rewards\EnrollRewardMemberAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardProgramStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Rewards\Ledgers\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

it('serves the member benefit catalog, redemption, grants, use, and cancel flow', function () {
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram('api-ben', RewardProgramMode::Points);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    $member = User::factory()->create();
    $memberId = (string) $member->getKey();
    $staffId = (string) Str::uuid();

    EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    CreditRewardPointsAction::run(
        'user', $memberId, $program->family_key, 'pts', 500,
        CarbonImmutable::parse('2026-06-11T00:00:00Z'), null,
        null, (string) $program->getKey(), 'seed:api-ben-'.$memberId,
        'system', null, 'test-seed', CarbonImmutable::parse('2026-06-11T00:00:00Z'),
    );

    CreateRewardBenefitItemAction::run(
        $program, 'api-seat', 'API seat', ['Good standing'], 200, 'pts', false, 5,
        'user', $staffId, 'staff',
    );

    Sanctum::actingAs($member);

    $catalog = $this->getJson('/api/v1/rewards/benefits/catalog')->assertOk();
    $itemId = $catalog->json('data.0.id');

    expect($catalog->json('data.0.available_units'))->toBe(5);

    $redeem = $this->postJson('/api/v1/rewards/benefits/redemptions', [
        'item_id' => $itemId,
        'family_key' => $program->family_key,
        'quantity' => 1,
        'redemption_key' => 'api-red-1',
        'adapter' => 'manual',
    ])->assertCreated();

    $grantId = $redeem->json('data.id');

    expect($redeem->json('data.status'))->toBe('reserved');

    $this->getJson('/api/v1/rewards/benefits/grants')->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson("/api/v1/rewards/benefits/grants/{$grantId}")->assertOk()
        ->assertJsonPath('data.status', 'reserved');

    // Issued-only consumption refuses while reserved; stranger reads 404.
    $this->postJson("/api/v1/rewards/benefits/grants/{$grantId}/consume", ['use_reference' => 'X'])
        ->assertStatus(422);

    $stranger = User::factory()->create();
    Sanctum::actingAs($stranger);
    $this->getJson("/api/v1/rewards/benefits/grants/{$grantId}")->assertNotFound();

    Sanctum::actingAs($member);
    $this->postJson("/api/v1/rewards/benefits/grants/{$grantId}/cancel", ['reason' => 'changed mind'])
        ->assertOk()->assertJsonPath('data.status', 'revoked');

    $this->getJson('/api/v1/rewards/benefits/catalog')->assertOk();
    expect($this->getJson('/api/v1/rewards/benefits/catalog')->json('data.0.available_units'))->toBe(5);
});
