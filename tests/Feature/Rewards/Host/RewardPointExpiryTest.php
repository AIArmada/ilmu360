<?php

declare(strict_types=1);

use App\Actions\Rewards\CreditRewardPointsAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardProgramStatus;
use App\Models\RewardPointLot;
use App\Models\User;
use App\Support\Rewards\RewardPointLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Rewards\Ledgers\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

it('expires due point lots through the operator command, idempotently', function (): void {
    [$program] = RewardLedgerFixtures::seedVersionedProgram('expiry-proof', RewardProgramMode::Points);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    $member = User::factory()->create();
    $memberId = (string) $member->getKey();

    CreditRewardPointsAction::run(
        'user', $memberId, $program->family_key, 'pts', 100,
        CarbonImmutable::parse('2026-06-01T00:00:00Z'),
        CarbonImmutable::parse('2026-06-15T00:00:00Z'),
        null, (string) $program->getKey(), 'seed:expiry-'.$memberId,
        'system', null, 'test-seed', CarbonImmutable::parse('2026-06-01T00:00:00Z'),
    );

    $exit = Artisan::call('rewards:expire-point-lots', ['--as-of' => '2026-06-16T00:00:00Z']);

    expect($exit)->toBe(0)
        ->and(RewardPointLot::query()->where('recipient_id', $memberId)->whereNotNull('expired_at')->count())->toBe(1);

    $buckets = RewardPointLedger::balancesFor('user', $memberId, (string) $program->family_key, 'pts', CarbonImmutable::parse('2026-06-16T00:00:00Z'));
    expect($buckets->available)->toBe(0);

    // Reruns converge: nothing left due.
    $again = Artisan::call('rewards:expire-point-lots', ['--as-of' => '2026-06-16T00:00:00Z']);

    expect($again)->toBe(0)
        ->and(Artisan::output())->toContain('0 lot movements');

    // Unexpired lots are untouched.
    CreditRewardPointsAction::run(
        'user', $memberId, $program->family_key, 'pts', 50,
        CarbonImmutable::parse('2026-06-10T00:00:00Z'),
        CarbonImmutable::parse('2026-12-01T00:00:00Z'),
        null, (string) $program->getKey(), 'seed:expiry-fresh-'.$memberId,
        'system', null, 'test-seed', CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    Artisan::call('rewards:expire-point-lots', ['--as-of' => '2026-06-16T00:00:00Z']);

    $kept = RewardPointLedger::balancesFor('user', $memberId, (string) $program->family_key, 'pts', CarbonImmutable::parse('2026-06-16T00:00:00Z'));
    expect($kept->available)->toBe(50);
});

it('refuses an unparseable expiry timestamp', function (): void {
    expect(Artisan::call('rewards:expire-point-lots', ['--as-of' => 'not-a-date']))->toBe(2);
});
