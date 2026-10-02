<?php

use AIArmada\Events\Actions\CreateEventSessionAction;
use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventTimeExpression;
use App\Actions\Events\SyncEventScheduleAction;
use App\Enums\PrayerReference;
use App\Enums\TimingMode;
use App\Models\Event;
use Database\Seeders\AIArmada\EventRoleSeeder;

beforeEach(function () {
    fakePrayerTimesApi();

    $this->seed(EventRoleSeeder::class);
});

it('rolls back the occurrence write when the prayer expression fails', function () {
    $event = Event::factory()->create(['status' => 'draft']);
    $event->forceFill(['schedule_kind' => ScheduleKind::MultiDay->value])->save();

    $occurrenceId = (string) $event->fresh()->primaryOccurrence->getKey();
    $beforeStartsAt = $event->fresh()->primaryOccurrence->starts_at->timestamp;
    $beforeCount = EventOccurrence::query()->where('event_id', $event->getKey())->count();

    // The factory already wrote its own expression; the failure lands only
    // on the standalone sync below, after the occurrence row is written.
    EventTimeExpression::creating(function (): void {
        throw new RuntimeException('Injected expression failure.');
    });

    EventTimeExpression::updating(function (): void {
        throw new RuntimeException('Injected expression failure.');
    });

    expect(fn () => app(SyncEventScheduleAction::class)->execute(
        event: $event,
        scheduleKind: ScheduleKind::Single,
        startsAt: now()->addDays(9)->setTime(20, 0),
        endsAt: null,
        timezone: 'Asia/Kuala_Lumpur',
        timingMode: TimingMode::PrayerRelative,
        prayerReference: PrayerReference::Maghrib->value,
        prayerOffset: 5,
        prayerDisplayText: 'Selepas Maghrib',
    ))->toThrow(RuntimeException::class, 'Injected expression failure.');

    expect(EventOccurrence::query()->where('event_id', $event->getKey())->count())->toBe($beforeCount)
        ->and(EventOccurrence::query()->findOrFail($occurrenceId)->starts_at->timestamp)->toBe($beforeStartsAt)
        ->and($event->fresh()->schedule_kind)->toBe(ScheduleKind::MultiDay);
});

it('leaves occurrence and session scoped expressions untouched', function () {
    $event = Event::factory()->create(['status' => 'draft']);
    $occurrence = $event->fresh()->primaryOccurrence;

    $session = app(CreateEventSessionAction::class)->handle($occurrence, [
        'title' => 'Scoped Session',
        'slug' => 'scoped-session-'.$event->getKey(),
        'starts_at' => now()->addDays(3),
        'status' => 'scheduled',
        'visibility' => 'public',
    ]);

    // Deterministic scopes: the factory may already own an event-level
    // expression, so rebuild all three scopes explicitly.
    EventTimeExpression::query()->where('event_id', $event->getKey())->delete();

    $eventLevel = EventTimeExpression::query()->create([
        'event_id' => $event->getKey(),
        'event_occurrence_id' => null,
        'event_session_id' => null,
        'time_mode' => 'prayer_relative',
        'anchor_type' => 'prayer',
        'anchor_code' => 'maghrib',
        'relation' => 'after',
        'offset_minutes' => 5,
        'display_label' => 'Selepas Maghrib',
    ]);

    $occurrenceLevel = EventTimeExpression::query()->create([
        'event_id' => $event->getKey(),
        'event_occurrence_id' => $occurrence->getKey(),
        'event_session_id' => null,
        'time_mode' => 'prayer_relative',
        'anchor_type' => 'prayer',
        'anchor_code' => 'isha',
        'relation' => 'after',
        'offset_minutes' => 15,
        'display_label' => 'Selepas Isyak',
    ]);

    $sessionLevel = EventTimeExpression::query()->create([
        'event_id' => $event->getKey(),
        'event_occurrence_id' => $occurrence->getKey(),
        'event_session_id' => $session->getKey(),
        'time_mode' => 'prayer_relative',
        'anchor_type' => 'prayer',
        'anchor_code' => 'fajr',
        'relation' => 'after',
        'offset_minutes' => 30,
        'display_label' => 'Selepas Subuh',
    ]);

    // Absolute mode deletes only the event-level prayer expression.
    app(SyncEventScheduleAction::class)->execute(
        event: $event,
        scheduleKind: ScheduleKind::Single,
        startsAt: now()->addDays(9)->setTime(20, 0),
        endsAt: null,
        timezone: 'Asia/Kuala_Lumpur',
        timingMode: TimingMode::Absolute,
    );

    expect(EventTimeExpression::query()->whereKey($eventLevel->getKey())->exists())->toBeFalse()
        ->and(EventTimeExpression::query()->whereKey($occurrenceLevel->getKey())->exists())->toBeTrue()
        ->and(EventTimeExpression::query()->whereKey($sessionLevel->getKey())->exists())->toBeTrue();

    // Prayer-relative mode upserts only the event-level expression.
    app(SyncEventScheduleAction::class)->execute(
        event: $event,
        scheduleKind: ScheduleKind::Single,
        startsAt: now()->addDays(9)->setTime(20, 0),
        endsAt: null,
        timezone: 'Asia/Kuala_Lumpur',
        timingMode: TimingMode::PrayerRelative,
        prayerReference: PrayerReference::Maghrib->value,
        prayerOffset: 5,
        prayerDisplayText: 'Selepas Maghrib',
    );

    $refreshedOccurrenceLevel = EventTimeExpression::query()->findOrFail($occurrenceLevel->getKey());
    $refreshedSessionLevel = EventTimeExpression::query()->findOrFail($sessionLevel->getKey());

    expect(EventTimeExpression::query()->where('event_id', $event->getKey())->whereNull('event_occurrence_id')->whereNull('event_session_id')->where('anchor_type', 'prayer')->count())->toBe(1)
        ->and($refreshedOccurrenceLevel->anchor_code)->toBe('isha')
        ->and($refreshedOccurrenceLevel->display_label)->toBe('Selepas Isyak')
        ->and($refreshedSessionLevel->anchor_code)->toBe('fajr')
        ->and($refreshedSessionLevel->display_label)->toBe('Selepas Subuh');
});

it('refreshes the passed event model with the synced schedule', function () {
    $event = Event::factory()->create(['status' => 'draft']);
    $event->forceFill(['schedule_kind' => ScheduleKind::MultiDay->value])->save();
    $event = $event->fresh();

    $startsAt = now()->addDays(12)->setTimezone('Asia/Kuala_Lumpur')->startOfMinute();

    app(SyncEventScheduleAction::class)->execute(
        event: $event,
        scheduleKind: ScheduleKind::Single,
        startsAt: $startsAt,
        endsAt: null,
        timezone: 'Asia/Kuala_Lumpur',
        timingMode: TimingMode::Absolute,
    );

    // The same instance carries the new schedule; no fresh() needed.
    expect($event->schedule_kind)->toBe(ScheduleKind::Single)
        ->and($event->relationLoaded('primaryOccurrence'))->toBeTrue();

    $primary = $event->getRelation('primaryOccurrence');

    expect($primary)->toBeInstanceOf(EventOccurrence::class)
        ->and((string) $primary->getKey())->toBe((string) $event->fresh()->primaryOccurrence->getKey())
        ->and($primary->starts_at->setTimezone('UTC')->format('Y-m-d H:i:s'))->toBe($startsAt->copy()->setTimezone('UTC')->format('Y-m-d H:i:s'))
        ->and($primary->ends_at)->toBeNull();
});
