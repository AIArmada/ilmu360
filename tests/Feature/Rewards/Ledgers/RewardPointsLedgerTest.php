<?php

use App\Actions\Rewards\CreditRewardPointsAction;
use App\Actions\Rewards\DebitRewardPointsAction;
use App\Actions\Rewards\ExpireRewardPointsAction;
use App\Actions\Rewards\RefundRewardPointsAction;
use App\Actions\Rewards\ReleaseRewardPointsAction;
use App\Actions\Rewards\ReserveRewardPointsAction;
use App\Enums\Rewards\RewardPointLotStatus;
use App\Models\RewardPointLot;
use App\Models\RewardPointMovement;
use App\Support\Rewards\RewardPointLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

function pointsIdentity(string $suffix = ''): array
{
    return ['user', (string) Str::uuid(), 'pts-fam-'.$suffix.Str::lower(Str::random(4)), 'pts'];
}

it('credits idempotent source-linked lots and reconciles buckets exactly', function () {
    [$type, $recipient, $family, $unit] = pointsIdentity('a');

    $first = CreditRewardPointsAction::run($type, $recipient, $family, $unit, 100, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-12-01T00:00:00Z'), null, null, 'op:recon-1', 'system', null, 'test-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    $replay = CreditRewardPointsAction::run($type, $recipient, $family, $unit, 100, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-12-01T00:00:00Z'), null, null, 'op:recon-1', 'system', null, 'test-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    expect((string) $replay->getKey())->toBe((string) $first->getKey())
        ->and(RewardPointLot::query()->count())->toBe(1)
        ->and(RewardPointMovement::query()->count())->toBe(1);

    CreditRewardPointsAction::run($type, $recipient, $family, $unit, 40, CarbonImmutable::parse('2026-08-01T00:00:00Z'), null, null, null, 'op:recon-2', 'system', null, 'test-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    $at = CarbonImmutable::parse('2026-07-01T00:00:00Z');
    $buckets = RewardPointLedger::balancesFor($type, $recipient, $family, $unit, $at);

    expect($buckets->available)->toBe(100)
        ->and($buckets->pending)->toBe(40)
        ->and($buckets->total())->toBe(140);
});

it('reserves oldest-expiring first and refuses to spend reserved or pending value', function () {
    [$type, $recipient, $family, $unit] = pointsIdentity('b');

    CreditRewardPointsAction::run($type, $recipient, $family, $unit, 50, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-07-10T00:00:00Z'), null, null, 'op:rsv-a', 'system', null, 'c', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    CreditRewardPointsAction::run($type, $recipient, $family, $unit, 50, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-09-10T00:00:00Z'), null, null, 'op:rsv-b', 'system', null, 'c', CarbonImmutable::parse('2026-06-01T00:00:00Z'));

    $movements = ReserveRewardPointsAction::run($type, $recipient, $family, $unit, 60, 'rsv:oldest-1', 'hold-order', CarbonImmutable::parse('2026-06-05T00:00:00Z'));

    expect($movements)->toHaveCount(2);

    $lots = RewardPointLot::query()->orderBy('expires_at')->get();
    $byLot = collect($movements)->mapWithKeys(fn (RewardPointMovement $m): array => [(string) $m->lot_id => (int) $m->delta])->all();

    expect($byLot[(string) $lots[0]->getKey()])->toBe(50)
        ->and($byLot[(string) $lots[1]->getKey()])->toBe(10);

    $replay = ReserveRewardPointsAction::run($type, $recipient, $family, $unit, 60, 'rsv:oldest-1', 'hold-order', CarbonImmutable::parse('2026-06-05T00:00:00Z'));

    expect($replay)->toHaveCount(2)
        ->and(RewardPointMovement::query()->where('kind', 'reserve')->count())->toBe(2);

    $buckets = RewardPointLedger::balancesFor($type, $recipient, $family, $unit, CarbonImmutable::parse('2026-06-06T00:00:00Z'));

    expect($buckets->available)->toBe(40)
        ->and($buckets->reserved)->toBe(60);

    expect(fn () => DebitRewardPointsAction::run($type, $recipient, $family, $unit, 41, 'spend:over-1', 'overspend', null, CarbonImmutable::parse('2026-06-06T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'Insufficient available');
});

it('releases to available when unexpired and expires immediately after expiry', function () {
    [$type, $recipient, $family, $unit] = pointsIdentity('c');

    CreditRewardPointsAction::run($type, $recipient, $family, $unit, 30, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-07-01T00:00:00Z'), null, null, 'op:rel-a', 'system', null, 'c', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    ReserveRewardPointsAction::run($type, $recipient, $family, $unit, 30, 'rsv:rel-1', 'hold', CarbonImmutable::parse('2026-06-05T00:00:00Z'));

    $released = ReleaseRewardPointsAction::run('rsv:rel-1', 'order-cancelled', CarbonImmutable::parse('2026-06-10T00:00:00Z'));

    expect($released[0]->kind->value)->toBe('release');

    $buckets = RewardPointLedger::balancesFor($type, $recipient, $family, $unit, CarbonImmutable::parse('2026-06-11T00:00:00Z'));

    expect($buckets->available)->toBe(30)
        ->and($buckets->reserved)->toBe(0);

    ReserveRewardPointsAction::run($type, $recipient, $family, $unit, 30, 'rsv:rel-2', 'hold', CarbonImmutable::parse('2026-06-12T00:00:00Z'));

    $expired = ReleaseRewardPointsAction::run('rsv:rel-2', 'late-cancel', CarbonImmutable::parse('2026-07-02T00:00:00Z'));

    expect($expired[0]->kind->value)->toBe('expire');

    $after = RewardPointLedger::balancesFor($type, $recipient, $family, $unit, CarbonImmutable::parse('2026-07-03T00:00:00Z'));

    expect($after->available)->toBe(0)
        ->and($after->expired)->toBe(30);
});

it('captures reservations exactly and debits available without going negative', function () {
    [$type, $recipient, $family, $unit] = pointsIdentity('d');

    CreditRewardPointsAction::run($type, $recipient, $family, $unit, 25, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:cap-a', 'system', null, 'c', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    ReserveRewardPointsAction::run($type, $recipient, $family, $unit, 15, 'rsv:cap-1', 'hold', CarbonImmutable::parse('2026-06-05T00:00:00Z'));

    expect(fn () => DebitRewardPointsAction::run($type, $recipient, $family, $unit, 10, 'spend:cap-partial', 'partial', 'rsv:cap-1', CarbonImmutable::parse('2026-06-06T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'exact reserved amount');

    $captured = DebitRewardPointsAction::run($type, $recipient, $family, $unit, 15, 'spend:cap-full', 'capture', 'rsv:cap-1', CarbonImmutable::parse('2026-06-06T00:00:00Z'));

    expect($captured)->toHaveCount(1)
        ->and($captured[0]->linked_movement_id)->not->toBeNull();

    $buckets = RewardPointLedger::balancesFor($type, $recipient, $family, $unit, CarbonImmutable::parse('2026-06-07T00:00:00Z'));

    expect($buckets->reserved)->toBe(0)
        ->and($buckets->spent)->toBe(15)
        ->and($buckets->available)->toBe(10);

    $direct = DebitRewardPointsAction::run($type, $recipient, $family, $unit, 10, 'spend:direct-1', 'spend', null, CarbonImmutable::parse('2026-06-08T00:00:00Z'));
    $replay = DebitRewardPointsAction::run($type, $recipient, $family, $unit, 10, 'spend:direct-1', 'spend', null, CarbonImmutable::parse('2026-06-08T00:00:00Z'));

    expect($replay)->toHaveCount(count($direct));

    $final = RewardPointLedger::balancesFor($type, $recipient, $family, $unit, CarbonImmutable::parse('2026-06-09T00:00:00Z'));

    expect($final->available)->toBe(0)
        ->and($final->spent)->toBe(25);
});

it('expires only unreserved remainders while reservations survive until resolution', function () {
    [$type, $recipient, $family, $unit] = pointsIdentity('e');

    CreditRewardPointsAction::run($type, $recipient, $family, $unit, 100, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-07-01T00:00:00Z'), null, null, 'op:exp-a', 'system', null, 'c', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    ReserveRewardPointsAction::run($type, $recipient, $family, $unit, 30, 'rsv:exp-1', 'hold', CarbonImmutable::parse('2026-06-05T00:00:00Z'));

    $expired = ExpireRewardPointsAction::run($type, $recipient, $family, $unit, CarbonImmutable::parse('2026-07-02T00:00:00Z'));

    expect($expired)->toHaveCount(1)
        ->and((int) $expired[0]->delta)->toBe(70);

    $lot = RewardPointLot::query()->firstOrFail();

    expect($lot->status)->toBe(RewardPointLotStatus::Open)
        ->and($lot->expired_at)->not->toBeNull();

    $buckets = RewardPointLedger::balancesFor($type, $recipient, $family, $unit, CarbonImmutable::parse('2026-07-03T00:00:00Z'));

    expect($buckets->expired)->toBe(70)
        ->and($buckets->reserved)->toBe(30)
        ->and($buckets->available)->toBe(0);

    $replay = ExpireRewardPointsAction::run($type, $recipient, $family, $unit, CarbonImmutable::parse('2026-07-04T00:00:00Z'));

    expect($replay)->toBe([]);

    ReleaseRewardPointsAction::run('rsv:exp-1', 'late-cancel', CarbonImmutable::parse('2026-07-05T00:00:00Z'));

    $settled = RewardPointLedger::balancesFor($type, $recipient, $family, $unit, CarbonImmutable::parse('2026-07-06T00:00:00Z'));

    expect($settled->expired)->toBe(100)
        ->and($settled->reserved)->toBe(0);
});

it('refunds linked debits into new lots capped at the debited amount', function () {
    [$type, $recipient, $family, $unit] = pointsIdentity('f');

    CreditRewardPointsAction::run($type, $recipient, $family, $unit, 50, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:ref-a', 'system', null, 'c', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
    DebitRewardPointsAction::run($type, $recipient, $family, $unit, 20, 'spend:ref-1', 'spend', null, CarbonImmutable::parse('2026-06-05T00:00:00Z'));

    $refunds = RefundRewardPointsAction::run('refund:ref-1', 'spend:ref-1', 12, 'partial-refund', CarbonImmutable::parse('2026-06-06T00:00:00Z'));

    expect($refunds)->toHaveCount(1)
        ->and($refunds[0]->kind->value)->toBe('refund')
        ->and(RewardPointLot::query()->count())->toBe(2);

    $buckets = RewardPointLedger::balancesFor($type, $recipient, $family, $unit, CarbonImmutable::parse('2026-06-07T00:00:00Z'));

    expect($buckets->available)->toBe(42)
        ->and($buckets->spent)->toBe(20);

    expect(fn () => RefundRewardPointsAction::run('refund:ref-over', 'spend:ref-1', 9, 'over-refund', CarbonImmutable::parse('2026-06-08T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'unrefunded debited amount');

    expect(fn () => RefundRewardPointsAction::run('refund:ref-unknown', 'spend:unknown-agg', 5, 'no-debit', CarbonImmutable::parse('2026-06-08T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'prior debit aggregate');
});

it('refuses negative, zero, overflowing, and incoherent point operations', function () {
    [$type, $recipient, $family, $unit] = pointsIdentity('g');

    expect(fn () => CreditRewardPointsAction::run($type, $recipient, $family, $unit, 0, CarbonImmutable::parse('2026-06-01T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, '1..');

    expect(fn () => CreditRewardPointsAction::run($type, $recipient, $family, 'bad unit!', 10, CarbonImmutable::parse('2026-06-01T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'Unit');

    expect(fn () => CreditRewardPointsAction::run($type, $recipient, $family, $unit, 10, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-06-01T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'after availability');

    expect(fn () => ReserveRewardPointsAction::run($type, $recipient, $family, $unit, 10, 'rsv:empty-1', 'hold', CarbonImmutable::parse('2026-06-05T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'Insufficient available');

    expect(fn () => ReleaseRewardPointsAction::run('rsv:unknown-1', 'x', CarbonImmutable::parse('2026-06-05T00:00:00Z')))
        ->toThrow(InvalidArgumentException::class, 'Unknown reservation');
});
