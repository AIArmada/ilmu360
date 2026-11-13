<?php

declare(strict_types=1);

// Rw0 characterization: the digest slot's frozen identity, transition
// matrix and delete refusal; global daily/weekly cadence config with the
// 20-hour calmness floor and recipient-timezone local_day; the same-day
// merge across programs/categories with window and delivery bookkeeping
// preserved; and replay of sending/sent/cancelled slots. These tests
// record current behavior only — no behavior changes.

use AIArmada\Rewards\Actions\GrantRewardBadgeAction;
use App\Features\Rewards\Actions\GratitudeDigests\DispatchDueRewardDigestsAction;
use App\Features\Rewards\Actions\GratitudeDigests\PlanRewardGratitudeDigestAction;
use App\Models\RewardDigestSlot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Host\Support\RewardCharacterizationFixtures;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

it('freezes digest identity columns and immutable transition timestamps', function (): void {
    $frozen = [
        'recipient_type' => 'person',
        'recipient_id' => (string) Str::uuid(),
        'local_day' => '2026-06-17',
        'plan_key' => 'gratitude:other:2026-06-17',
        'timezone' => 'Asia/Tokyo',
        'due_at' => CarbonImmutable::parse('2026-06-17T08:00:00Z'),
    ];

    foreach ($frozen as $field => $value) {
        $slot = RewardCharacterizationFixtures::slot(RewardDigestSlot::STATUS_PLANNED);
        $slot->{$field} = $value;

        expect(fn () => $slot->save())
            ->toThrow(LogicException::class, "[{$field}] is frozen at plan time");
    }

    foreach (['sent_at', 'cancelled_at'] as $field) {
        $slot = RewardCharacterizationFixtures::slot(RewardDigestSlot::STATUS_PLANNED, [
            $field => CarbonImmutable::parse('2026-06-15T08:00:00Z'),
        ]);
        $slot->{$field} = CarbonImmutable::parse('2026-06-15T09:00:00Z');

        expect(fn () => $slot->save())
            ->toThrow(LogicException::class, "[{$field}] is an immutable transition timestamp");
    }

    // Payload material stays mutable so same-day planning can merge sections.
    $slot = RewardCharacterizationFixtures::slot(RewardDigestSlot::STATUS_PLANNED);
    $slot->categories = ['awards', 'badges'];
    $slot->payload = ['total' => 2, 'text' => 'merged material'];
    $slot->save();

    expect($slot->refresh()->categories)->toBe(['awards', 'badges'])
        ->and($slot->payload['total'])->toBe(2);
});

it('enforces the digest status transition matrix', function (): void {
    $allowed = [
        [RewardDigestSlot::STATUS_PLANNED, RewardDigestSlot::STATUS_SENDING],
        [RewardDigestSlot::STATUS_PLANNED, RewardDigestSlot::STATUS_CANCELLED],
        [RewardDigestSlot::STATUS_SENDING, RewardDigestSlot::STATUS_SENT],
        [RewardDigestSlot::STATUS_SENDING, RewardDigestSlot::STATUS_CANCELLED],
        [RewardDigestSlot::STATUS_SENDING, RewardDigestSlot::STATUS_PLANNED],
    ];

    foreach ($allowed as [$from, $to]) {
        $slot = RewardCharacterizationFixtures::slot($from);
        $slot->status = $to;
        $slot->save();

        expect($slot->refresh()->status)->toBe($to);
    }

    $refused = [
        [RewardDigestSlot::STATUS_PLANNED, RewardDigestSlot::STATUS_SENT],
        [RewardDigestSlot::STATUS_SENT, RewardDigestSlot::STATUS_SENDING],
        [RewardDigestSlot::STATUS_SENT, RewardDigestSlot::STATUS_PLANNED],
        [RewardDigestSlot::STATUS_SENT, RewardDigestSlot::STATUS_CANCELLED],
        [RewardDigestSlot::STATUS_CANCELLED, RewardDigestSlot::STATUS_SENDING],
        [RewardDigestSlot::STATUS_CANCELLED, RewardDigestSlot::STATUS_PLANNED],
        [RewardDigestSlot::STATUS_CANCELLED, RewardDigestSlot::STATUS_SENT],
    ];

    foreach ($refused as [$from, $to]) {
        $slot = RewardCharacterizationFixtures::slot($from);
        $slot->status = $to;

        expect(fn () => $slot->save())
            ->toThrow(LogicException::class, 'Invalid reward digest slot status transition.');
    }
});

it('refuses digest slot model deletes', function (): void {
    $slot = RewardCharacterizationFixtures::slot(RewardDigestSlot::STATUS_PLANNED);

    expect(fn () => $slot->delete())
        ->toThrow(LogicException::class, 'cannot be deleted');

    expect(RewardDigestSlot::query()->whereKey($slot->getKey())->exists())->toBeTrue();
});

it('uses the global daily delivery time instead of a per-user preference', function (): void {
    $lateUser = RewardCharacterizationFixtures::digestUser(['digest_delivery_time' => '21:30']);
    $lateSlot = PlanRewardGratitudeDigestAction::run(
        'user', (string) $lateUser->getKey(), ['awards'], CarbonImmutable::parse('2026-06-15T10:00:00Z'),
    );

    expect($lateSlot->due_at?->toIso8601String())->toBe('2026-06-16T21:30:00+00:00')
        ->and($lateSlot->local_day)->toBe('2026-06-16');

    // The delivery time is read from global config at planning time; a user
    // cannot carry a personal digest time that changes the send instant.
    $earlyUser = RewardCharacterizationFixtures::digestUser(['digest_delivery_time' => '06:15']);
    $earlySlot = PlanRewardGratitudeDigestAction::run(
        'user', (string) $earlyUser->getKey(), ['awards'], CarbonImmutable::parse('2026-06-15T10:00:00Z'),
    );

    expect($earlySlot->due_at?->toIso8601String())->toBe('2026-06-16T06:15:00+00:00')
        ->and($earlySlot->local_day)->toBe('2026-06-16');
});

it('applies the twenty-hour calmness floor without crossing the daily local day', function (): void {
    $user = RewardCharacterizationFixtures::digestUser(['timezone' => 'Asia/Kuala_Lumpur']);

    // 2026-06-15T15:30Z is 23:30 on the 15th in Kuala Lumpur. The next
    // local 08:00 is only 8.5 hours out, so the floor lands at 19:30 on
    // the same target local day rather than deferring a further day.
    $slot = PlanRewardGratitudeDigestAction::run(
        'user', (string) $user->getKey(), ['awards'], CarbonImmutable::parse('2026-06-15T15:30:00Z'),
    );

    expect($slot->due_at?->toIso8601String())->toBe('2026-06-16T11:30:00+00:00')
        ->and($slot->local_day)->toBe('2026-06-16')
        ->and($slot->timezone)->toBe('Asia/Kuala_Lumpur');
});

it('applies the calmness floor inside the weekly target day', function (): void {
    $user = RewardCharacterizationFixtures::digestUser(
        ['timezone' => 'UTC', 'digest_delivery_time' => '08:00', 'digest_weekly_day' => 1],
        ['share_gratitude_digest' => ['enabled' => true, 'cadence' => 'weekly']],
    );

    // Sunday 2026-06-21T20:00Z: Monday 08:00 is only 12 hours out, so the
    // floor pushes to Monday 16:00 — still the configured weekly day.
    $slot = PlanRewardGratitudeDigestAction::run(
        'user', (string) $user->getKey(), ['awards'], CarbonImmutable::parse('2026-06-21T20:00:00Z'),
    );

    expect($slot->due_at?->toIso8601String())->toBe('2026-06-22T16:00:00+00:00')
        ->and($slot->local_day)->toBe('2026-06-22');
});

it('buckets weekly local_day in the recipient timezone', function (): void {
    $user = RewardCharacterizationFixtures::digestUser(
        ['timezone' => 'Asia/Kuala_Lumpur', 'digest_delivery_time' => '09:00', 'digest_weekly_day' => 1],
        ['share_gratitude_digest' => ['enabled' => true, 'cadence' => 'weekly']],
    );

    // Friday 2026-06-19T10:00Z is Friday evening in Kuala Lumpur; the next
    // Monday 09:00 local is 2026-06-22T01:00Z.
    $slot = PlanRewardGratitudeDigestAction::run(
        'user', (string) $user->getKey(), ['awards'], CarbonImmutable::parse('2026-06-19T10:00:00Z'),
    );

    expect($slot->due_at?->toIso8601String())->toBe('2026-06-22T01:00:00+00:00')
        ->and($slot->local_day)->toBe('2026-06-22')
        ->and($slot->timezone)->toBe('Asia/Kuala_Lumpur');
});

it('merges same-day categories across programs and preserves window and delivery bookkeeping', function (): void {
    Queue::fake();

    $user = RewardCharacterizationFixtures::digestUser();
    [$programA] = RewardCharacterizationFixtures::digestProgram('rw0-merge-a', $user);
    [$programB] = RewardCharacterizationFixtures::digestProgram('rw0-merge-b', $user);

    RewardCharacterizationFixtures::approveAward($programA, $user, 'rw0-merge-source-a', '2026-06-15T10:00:00Z');

    $slot = RewardCharacterizationFixtures::slotFor($user);
    $firstWindowFrom = $slot->payload['window']['from'];

    expect($slot->categories)->toBe(['awards'])
        ->and($slot->payload['total'])->toBe(1);

    // One failed delivery attempt records bookkeeping and returns the slot
    // to planned, exactly as the hourly reconcile does.
    DispatchDueRewardDigestsAction::run(50, $slot->due_at->addMinute());

    expect($slot->refresh()->status)->toBe(RewardDigestSlot::STATUS_SENDING);

    RewardCharacterizationFixtures::failAllDeliveries($slot);

    DispatchDueRewardDigestsAction::run(50, $slot->due_at->addMinute());

    $slot->refresh();

    expect($slot->status)->toBe(RewardDigestSlot::STATUS_PLANNED)
        ->and($slot->payload['delivery']['attempts'])->toBe(1);

    $channels = $slot->payload['delivery']['channels'];

    // A badge earned under a different program on the same local day merges
    // into the same ceiling slot; it extends the window, unions categories
    // and keeps the delivery bookkeeping.
    GrantRewardBadgeAction::run(
        'user', (string) $user->getKey(), $programB->family_key, 'first-share', (string) $programB->getKey(), null,
        'user', RewardLedgerFixtures::staffId(), 'staff', 'earned', null, CarbonImmutable::parse('2026-06-15T11:00:00Z'),
    );

    $merged = $slot->refresh();

    expect((string) $merged->getKey())->toBe((string) $slot->getKey())
        ->and($merged->status)->toBe(RewardDigestSlot::STATUS_PLANNED)
        ->and($merged->categories)->toBe(['awards', 'badges'])
        ->and($merged->payload['window']['from'])->toBe($firstWindowFrom)
        ->and($merged->payload['window']['to'])->toBe('2026-06-15T11:00:00+00:00')
        ->and($merged->payload['delivery']['attempts'])->toBe(1)
        ->and($merged->payload['delivery']['channels'])->toBe($channels)
        ->and($merged->payload['total'])->toBe(2);
});

it('replays sending, sent, and cancelled slots untouched', function (string $status): void {
    $user = RewardCharacterizationFixtures::digestUser();
    $decided = CarbonImmutable::parse('2026-06-15T10:00:00Z');

    $slot = RewardCharacterizationFixtures::slot($status, [
        'recipient_id' => (string) $user->getKey(),
        'plan_key' => 'gratitude:'.(string) $user->getKey().':2026-06-16',
        'categories' => ['awards'],
        'payload' => [
            'window' => ['from' => '2026-06-14T10:00:00+00:00', 'to' => '2026-06-15T10:00:00+00:00'],
            'total' => 1,
            'text' => 'original digest material',
        ],
    ]);

    $replay = PlanRewardGratitudeDigestAction::run(
        'user', (string) $user->getKey(), ['badges'], $decided,
    );

    expect((string) $replay->getKey())->toBe((string) $slot->getKey())
        ->and($replay->status)->toBe($status)
        ->and($replay->categories)->toBe(['awards'])
        ->and($replay->payload['text'])->toBe('original digest material');
})->with([
    'sending' => [RewardDigestSlot::STATUS_SENDING],
    'sent' => [RewardDigestSlot::STATUS_SENT],
    'cancelled' => [RewardDigestSlot::STATUS_CANCELLED],
]);

it('keeps the calendar day frozen when the recipient timezone changes later', function (): void {
    $user = RewardCharacterizationFixtures::digestUser(['timezone' => 'UTC']);

    $slot = PlanRewardGratitudeDigestAction::run(
        'user', (string) $user->getKey(), ['awards'], CarbonImmutable::parse('2026-06-15T10:00:00Z'),
    );

    $originalDay = $slot->local_day;
    $originalDue = $slot->due_at?->toIso8601String();

    $user->forceFill(['timezone' => 'Asia/Kuala_Lumpur'])->save();

    $replay = PlanRewardGratitudeDigestAction::run(
        'user', (string) $user->getKey(), ['badges'], CarbonImmutable::parse('2026-06-15T10:00:00Z'),
    );

    expect((string) $replay->getKey())->toBe((string) $slot->getKey())
        ->and($replay->local_day)->toBe($originalDay)
        ->and($replay->due_at?->toIso8601String())->toBe($originalDue)
        ->and($replay->timezone)->toBe('UTC');
});
