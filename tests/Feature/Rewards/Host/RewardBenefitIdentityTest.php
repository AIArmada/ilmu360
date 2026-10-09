<?php

declare(strict_types=1);

// R6 proof: redemption identity binds caller + terms, coupon eligibility is
// real recipient binding (not per-user limits or wallet association),
// manual recall is a reachable recorded proof, and post-expiry revocation
// follows frozen refund terms. Concurrency closure lives in
// RewardFinanceRaceTest (fulfill/cancel/usage/reversal/stock races).

use AIArmada\Vouchers\Actions\RecordVoucherUsage;
use AIArmada\Vouchers\Exceptions\InvalidVoucherException;
use AIArmada\Vouchers\Exceptions\VoucherExpiredException;
use AIArmada\Vouchers\Models\Voucher as VoucherModel;
use AIArmada\Vouchers\Models\VoucherUsage;
use AIArmada\Vouchers\Services\VoucherValidator;
use Akaunting\Money\Currency;
use Akaunting\Money\Money;
use App\Actions\Rewards\CancelRewardBenefitGrantAction;
use App\Actions\Rewards\ConsumeRewardBenefitAction;
use App\Actions\Rewards\CreateRewardBenefitItemAction;
use App\Actions\Rewards\CreditRewardPointsAction;
use App\Actions\Rewards\EnrollRewardMemberAction;
use App\Actions\Rewards\FulfillRewardBenefitAction;
use App\Actions\Rewards\RecordRewardBenefitRecallAction;
use App\Actions\Rewards\RedeemRewardBenefitAction;
use App\Actions\Rewards\RevokeRewardBenefitGrantAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Enums\Rewards\RewardBenefitGrantStatus;
use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardProgramStatus;
use App\Models\RewardBenefitGrant;
use App\Models\RewardBenefitItem;
use App\Models\User;
use App\Support\Rewards\RewardBenefitStockLedger;
use App\Support\Rewards\RewardPointLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Rewards\Ledgers\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

function bidProgram(string $prefix): array
{
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram($prefix, RewardProgramMode::Points);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    return [$program, $version];
}

function bidFundedMember($program, $version, int $points, ?CarbonImmutable $expiresAt = null): string
{
    $memberId = (string) Str::uuid();

    EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    CreditRewardPointsAction::run(
        'user', $memberId, $program->family_key, 'pts', $points,
        CarbonImmutable::parse('2026-06-11T00:00:00Z'), $expiresAt,
        null, (string) $program->getKey(), 'seed:bid-'.$memberId.'-'.Str::random(6),
        'system', null, 'test-seed', CarbonImmutable::parse('2026-06-11T00:00:00Z'),
    );

    return $memberId;
}

function bidItem($program, string $key, int $cost = 200, int $capacity = 5): RewardBenefitItem
{
    return CreateRewardBenefitItemAction::run(
        $program, $key, 'Benefit '.$key, ['Good standing'], $cost, 'pts', false, $capacity,
        'user', (string) Str::uuid(), 'staff',
    );
}

function bidCouponMember($program, $version, int $points = 400): User
{
    $member = User::factory()->create();
    $memberId = (string) $member->getKey();

    EnrollRewardMemberAction::run(
        $program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    CreditRewardPointsAction::run(
        'user', $memberId, $program->family_key, 'pts', $points,
        CarbonImmutable::parse('2026-06-11T00:00:00Z'), null,
        null, (string) $program->getKey(), 'seed:bidc-'.$memberId,
        'system', null, 'test-seed', CarbonImmutable::parse('2026-06-11T00:00:00Z'),
    );

    return $member;
}

it('refuses a cross-recipient same-key redemption without leaking the bound grant', function () {
    [$program, $version] = bidProgram('bid-xrecip');
    $first = bidFundedMember($program, $version, 500);
    $second = bidFundedMember($program, $version, 500);
    $item = bidItem($program, 'xrecip-seat');

    $grant = RedeemRewardBenefitAction::run(
        'user', $first, $program->family_key, $item, 1, 'shared-key-1', 'manual',
        'user', $first, 'member', null, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    expect(fn () => RedeemRewardBenefitAction::run(
        'user', $second, $program->family_key, $item, 1, 'shared-key-1', 'manual',
        'user', $second, 'member', null, CarbonImmutable::parse('2026-06-15T01:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'already bound to different terms');

    // The refusal carries no stored data and leaves no effects: the
    // second recipient owns nothing and spent nothing.
    expect(RewardBenefitGrant::query()->where('grant_key', 'redeem:shared-key-1')->count())->toBe(1)
        ->and(RewardBenefitGrant::query()->where('recipient_id', $second)->count())->toBe(0)
        ->and(RewardPointLedger::balancesFor('user', $second, $program->family_key, 'pts', CarbonImmutable::parse('2026-07-01T00:00:00Z'))->available)->toBe(500)
        ->and(RewardBenefitStockLedger::balances($item->refresh())['available'])->toBe(4)
        ->and($grant->refresh()->recipient_id)->toBe($first);
});

it('refuses same-key redemptions with changed item, quantity, adapter, or family', function () {
    [$program, $version] = bidProgram('bid-xterms');
    [$other] = [bidProgram('bid-xterms2')[0]];
    $member = bidFundedMember($program, $version, 2000);
    $item = bidItem($program, 'xterms-a');
    $otherItem = bidItem($program, 'xterms-b');

    RedeemRewardBenefitAction::run(
        'user', $member, $program->family_key, $item, 1, 'terms-key-1', 'manual',
        'user', $member, 'member', null, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $replay = function (string $key, $tryItem, int $qty, string $adapter, string $family) use ($member) {
        return function () use ($member, $key, $tryItem, $qty, $adapter, $family): void {
            RedeemRewardBenefitAction::run(
                'user', $member, $family, $tryItem, $qty, $key, $adapter,
                'user', $member, 'member', null, CarbonImmutable::parse('2026-06-15T01:00:00Z'),
            );
        };
    };

    expect($replay('terms-key-1', $otherItem, 1, 'manual', $program->family_key))
        ->toThrow(InvalidArgumentException::class, 'already bound to different terms');
    expect($replay('terms-key-1', $item, 2, 'manual', $program->family_key))
        ->toThrow(InvalidArgumentException::class, 'already bound to different terms');
    expect($replay('terms-key-1', $item, 1, 'coupon', $program->family_key))
        ->toThrow(InvalidArgumentException::class, 'already bound to different terms');
    expect($replay('terms-key-1', $item, 1, 'manual', $other->family_key))
        ->toThrow(InvalidArgumentException::class, 'already bound to different terms');

    expect(RewardBenefitGrant::query()->where('grant_key', 'redeem:terms-key-1')->count())->toBe(1);
});

it('replays identical-term redemptions onto the stored grant exactly once', function () {
    [$program, $version] = bidProgram('bid-replay');
    $member = bidFundedMember($program, $version, 500);
    $item = bidItem($program, 'replay-seat');

    $first = RedeemRewardBenefitAction::run(
        'user', $member, $program->family_key, $item, 1, 'replay-key-1', 'manual',
        'user', $member, 'member', null, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $second = RedeemRewardBenefitAction::run(
        'user', $member, $program->family_key, $item, 1, 'replay-key-1', 'manual',
        'user', $member, 'member', null, CarbonImmutable::parse('2026-06-15T01:00:00Z'),
    );

    expect((string) $second->getKey())->toBe((string) $first->getKey())
        ->and(RewardBenefitGrant::query()->where('grant_key', 'redeem:replay-key-1')->count())->toBe(1)
        ->and(RewardPointLedger::balancesFor('user', $member, $program->family_key, 'pts', CarbonImmutable::parse('2026-07-01T00:00:00Z'))->reserved)->toBe(200)
        ->and(RewardBenefitStockLedger::balances($item->refresh())['available'])->toBe(4);
});

it('never serves another member’s grant through the member redemption endpoint', function () {
    [$program, $version] = bidProgram('bid-api');
    $first = User::factory()->create();
    $second = User::factory()->create();

    foreach ([$first, $second] as $user) {
        $id = (string) $user->getKey();

        EnrollRewardMemberAction::run(
            $program, 'user', $id, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'),
        );

        CreditRewardPointsAction::run(
            'user', $id, $program->family_key, 'pts', 500,
            CarbonImmutable::parse('2026-06-11T00:00:00Z'), null,
            null, (string) $program->getKey(), 'seed:bidapi-'.$id,
            'system', null, 'test-seed', CarbonImmutable::parse('2026-06-11T00:00:00Z'),
        );
    }

    $item = bidItem($program, 'api-seat');

    Sanctum::actingAs($first);
    $this->postJson('/api/v1/rewards/benefits/redemptions', [
        'item_id' => (string) $item->getKey(),
        'family_key' => $program->family_key,
        'quantity' => 1,
        'redemption_key' => 'api-shared-1',
        'adapter' => 'manual',
    ])->assertCreated();

    Sanctum::actingAs($second);
    $refused = $this->postJson('/api/v1/rewards/benefits/redemptions', [
        'item_id' => (string) $item->getKey(),
        'family_key' => $program->family_key,
        'quantity' => 1,
        'redemption_key' => 'api-shared-1',
        'adapter' => 'manual',
    ])->assertStatus(422);

    // No grant payload leaks to the foreign caller.
    expect($refused->json('data'))->toBeNull()
        ->and(RewardBenefitGrant::query()->where('recipient_id', (string) $second->getKey())->count())->toBe(0);
});

it('binds issued coupon codes to the recipient at validation and usage', function () {
    [$program, $version] = bidProgram('bid-coupon');
    $member = bidCouponMember($program, $version);
    $memberId = (string) $member->getKey();
    $stranger = User::factory()->create();
    $staff = (string) Str::uuid();
    $operator = (string) Str::uuid();

    $item = CreateRewardBenefitItemAction::run(
        $program, 'bound-credit', 'Bound credit', ['Good standing'], 400, 'pts', false, 3,
        'user', $staff, 'staff', null, ['voucher' => ['type' => 'fixed', 'value' => 2000, 'currency' => 'MYR']],
    );

    $grant = RedeemRewardBenefitAction::run(
        'user', $memberId, $program->family_key, $item, 1, 'bound-1', 'coupon',
        'user', $memberId, 'member', null, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $issued = FulfillRewardBenefitAction::run(
        $grant, [], 'user', $operator, 'staff', CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $code = (string) $issued->external_ref;

    // The issued voucher carries the generic assignee marker.
    expect(VoucherModel::query()->where('code', $code)->firstOrFail()->metadata['assignee'])
        ->toBe(['type' => $member->getMorphClass(), 'id' => $memberId]);

    $validator = app(VoucherValidator::class);

    Auth::setUser($member);
    expect($validator->validate($code, null)->isValid)->toBeTrue();

    // Another account cannot validate the issued code …
    Auth::setUser($stranger);
    $foreign = $validator->validate($code, null);
    expect($foreign->isValid)->toBeFalse()
        ->and($foreign->reason)->toContain('another account');

    Auth::logout();

    // … cannot record usage on it, attributed or not …
    expect(fn () => RecordVoucherUsage::run(
        $code, new Money(2000, new Currency('MYR')), 'reward-benefit',
        ['idempotency_key' => 'foreign-use-1'], $stranger,
    ))->toThrow(InvalidVoucherException::class, 'another account');

    expect(fn () => RecordVoucherUsage::run(
        $code, new Money(2000, new Currency('MYR')), 'reward-benefit',
        ['idempotency_key' => 'ghost-use-1'],
    ))->toThrow(InvalidVoucherException::class, 'another account');

    expect(VoucherUsage::query()->whereHas('voucher', fn ($query) => $query->where('code', $code))->count())->toBe(0);

    // … while the recipient uses it normally.
    $usage = RecordVoucherUsage::run(
        $code, new Money(2000, new Currency('MYR')), 'reward-benefit',
        ['idempotency_key' => 'bound-use-1'], $member,
    );

    expect($usage->redeemed_by_id)->toBe($memberId);

    // Consumption auto-resolves the recipient's canonical usage.
    $consumed = ConsumeRewardBenefitAction::run(
        $issued->refresh(), [], 'user', $memberId, 'member', CarbonImmutable::parse('2026-06-16T02:00:00Z'),
    );

    expect($consumed->status)->toBe(RewardBenefitGrantStatus::Consumed);
});

it('refuses consumption on a foreign usage row even when cited explicitly', function () {
    [$program, $version] = bidProgram('bid-foreign');
    $member = bidCouponMember($program, $version);
    $memberId = (string) $member->getKey();
    $stranger = User::factory()->create();
    $staff = (string) Str::uuid();
    $operator = (string) Str::uuid();

    $item = CreateRewardBenefitItemAction::run(
        $program, 'foreign-credit', 'Foreign credit', ['Good standing'], 400, 'pts', false, 3,
        'user', $staff, 'staff', null, ['voucher' => ['type' => 'fixed', 'value' => 2000, 'currency' => 'MYR']],
    );

    $grant = RedeemRewardBenefitAction::run(
        'user', $memberId, $program->family_key, $item, 1, 'foreign-1', 'coupon',
        'user', $memberId, 'member', null, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $issued = FulfillRewardBenefitAction::run(
        $grant, [], 'user', $operator, 'staff', CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    // White-box guard proof: a foreign usage row for the voucher cannot
    // exist through RecordVoucherUsage (assignee-bound), so it is placed
    // directly to prove consumption still refuses it.
    $voucher = VoucherModel::query()->where('code', (string) $issued->external_ref)->firstOrFail();

    $foreign = VoucherUsage::query()->create([
        'voucher_id' => $voucher->getKey(),
        'discount_amount' => 2000,
        'currency' => 'MYR',
        'channel' => 'web',
        'redeemed_by_type' => $stranger->getMorphClass(),
        'redeemed_by_id' => $stranger->getKey(),
        'used_at' => CarbonImmutable::parse('2026-06-16T01:00:00Z'),
    ]);

    expect(fn () => ConsumeRewardBenefitAction::run(
        $issued, ['voucher_usage_id' => (string) $foreign->getKey()],
        'user', $memberId, 'member', CarbonImmutable::parse('2026-06-16T02:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'not redeemed by the grant recipient');

    expect($issued->refresh()->status)->toBe(RewardBenefitGrantStatus::Issued);
});

it('refuses usage recording after revocation expires the coupon', function () {
    [$program, $version] = bidProgram('bid-revuse');
    $member = bidCouponMember($program, $version);
    $memberId = (string) $member->getKey();
    $staff = (string) Str::uuid();
    $operator = (string) Str::uuid();

    $item = CreateRewardBenefitItemAction::run(
        $program, 'revuse-credit', 'Revuse credit', ['Good standing'], 400, 'pts', false, 3,
        'user', $staff, 'staff', null, ['voucher' => ['type' => 'fixed', 'value' => 2000, 'currency' => 'MYR']],
    );

    $grant = RedeemRewardBenefitAction::run(
        'user', $memberId, $program->family_key, $item, 1, 'revuse-1', 'coupon',
        'user', $memberId, 'member', null, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $issued = FulfillRewardBenefitAction::run(
        $grant, [], 'user', $operator, 'staff', CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $revoked = RevokeRewardBenefitGrantAction::run(
        $issued, 'user', $operator, 'staff', 'unused-recall', CarbonImmutable::parse('2026-06-16T01:00:00Z'),
    );

    expect($revoked->status)->toBe(RewardBenefitGrantStatus::Revoked);

    expect(fn () => RecordVoucherUsage::run(
        (string) $issued->external_ref, new Money(2000, new Currency('MYR')), 'reward-benefit',
        ['idempotency_key' => 'late-use-1'], $member,
    ))->toThrow(VoucherExpiredException::class);
});

it('records manual recalls through the reachable action and refuses replays', function () {
    [$program, $version] = bidProgram('bid-recall');
    $member = bidFundedMember($program, $version, 300);
    [$staff, $operator] = [(string) Str::uuid(), (string) Str::uuid()];
    $item = bidItem($program, 'recall-seat');

    $grant = RedeemRewardBenefitAction::run(
        'user', $member, $program->family_key, $item, 1, 'recall-1', 'manual',
        'user', $member, 'member', null, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    // Reserved grants cannot record a handoff recall.
    expect(fn () => RecordRewardBenefitRecallAction::run(
        $grant, 'EARLY-1', 'user', $operator, 'staff', CarbonImmutable::parse('2026-06-15T01:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'Only issued grants');

    $issued = FulfillRewardBenefitAction::run(
        $grant, ['handoff_reference' => 'DESK-9'], 'user', $operator, 'staff',
        CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    $recorded = RecordRewardBenefitRecallAction::run(
        $issued, 'RECALL-9', 'user', $operator, 'staff', CarbonImmutable::parse('2026-06-16T01:00:00Z'),
    );

    expect($recorded->evidence['recall_reference'])->toBe('RECALL-9');

    expect(fn () => RecordRewardBenefitRecallAction::run(
        $recorded->refresh(), 'RECALL-10', 'user', $operator, 'staff', CarbonImmutable::parse('2026-06-16T02:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'already recorded');

    $revoked = RevokeRewardBenefitGrantAction::run(
        $recorded->refresh(), 'user', $operator, 'staff', 'seat-recalled', CarbonImmutable::parse('2026-06-16T03:00:00Z'),
    );

    expect($revoked->status)->toBe(RewardBenefitGrantStatus::Revoked);
});

it('revokes after lot expiry into expired value under frozen refund terms', function () {
    [$program, $version] = bidProgram('bid-postexp');
    $member = bidFundedMember($program, $version, 300, CarbonImmutable::parse('2026-06-20T00:00:00Z'));
    [$staff, $operator] = [(string) Str::uuid(), (string) Str::uuid()];
    $item = bidItem($program, 'postexp-seat');

    $grant = RedeemRewardBenefitAction::run(
        'user', $member, $program->family_key, $item, 1, 'postexp-1', 'manual',
        'user', $member, 'member', null, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $issued = FulfillRewardBenefitAction::run(
        $grant, ['handoff_reference' => 'DESK-11'], 'user', $operator, 'staff',
        CarbonImmutable::parse('2026-06-16T00:00:00Z'),
    );

    RecordRewardBenefitRecallAction::run(
        $issued, 'RECALL-11', 'user', $operator, 'staff', CarbonImmutable::parse('2026-06-21T00:00:00Z'),
    );

    // Revocation after the captured lots expired: the refund inherits
    // the past expiry and reports as expired value — never spendable
    // resurrection — while stock restocks exactly once.
    $revoked = RevokeRewardBenefitGrantAction::run(
        $issued->refresh(), 'user', $operator, 'staff', 'late-recall', CarbonImmutable::parse('2026-06-21T01:00:00Z'),
    );

    $buckets = RewardPointLedger::balancesFor('user', $member, $program->family_key, 'pts', CarbonImmutable::parse('2026-06-22T00:00:00Z'));

    expect($revoked->status)->toBe(RewardBenefitGrantStatus::Revoked)
        ->and($buckets->available)->toBe(0)
        ->and($buckets->expired)->toBe(300)
        ->and(RewardBenefitStockLedger::balances($item->refresh())['available'])->toBe(5);
});

it('refuses sequential over-reservation beyond capacity', function () {
    [$program, $version] = bidProgram('bid-cap');
    $first = bidFundedMember($program, $version, 500);
    $second = bidFundedMember($program, $version, 500);
    $item = bidItem($program, 'cap-seat', 200, 1);

    RedeemRewardBenefitAction::run(
        'user', $first, $program->family_key, $item, 1, 'cap-1', 'manual',
        'user', $first, 'member', null, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    expect(fn () => RedeemRewardBenefitAction::run(
        'user', $second, $program->family_key, $item, 1, 'cap-2', 'manual',
        'user', $second, 'member', null, CarbonImmutable::parse('2026-06-15T01:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'Insufficient benefit stock');

    expect(RewardBenefitStockLedger::balances($item->refresh())['available'])->toBe(0);
});

it('restores exactly once when cancel races nothing but replays the key', function () {
    [$program, $version] = bidProgram('bid-cancelonce');
    $member = bidFundedMember($program, $version, 500);
    $item = bidItem($program, 'cancelonce-seat');

    $grant = RedeemRewardBenefitAction::run(
        'user', $member, $program->family_key, $item, 1, 'cancelonce-1', 'manual',
        'user', $member, 'member', null, CarbonImmutable::parse('2026-06-15T00:00:00Z'),
    );

    $cancelled = CancelRewardBenefitGrantAction::run(
        $grant, 'user', $member, 'member', 'changed-mind', CarbonImmutable::parse('2026-06-15T01:00:00Z'),
    );

    expect($cancelled->status)->toBe(RewardBenefitGrantStatus::Revoked);

    expect(fn () => CancelRewardBenefitGrantAction::run(
        $cancelled->refresh(), 'user', $member, 'member', 'again', CarbonImmutable::parse('2026-06-15T02:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'Only reserved grants');

    $buckets = RewardPointLedger::balancesFor('user', $member, $program->family_key, 'pts', CarbonImmutable::parse('2026-07-01T00:00:00Z'));

    expect($buckets->available)->toBe(500)
        ->and($buckets->reserved)->toBe(0)
        ->and(RewardBenefitStockLedger::balances($item->refresh())['available'])->toBe(5);
});
