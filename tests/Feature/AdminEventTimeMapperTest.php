<?php

use App\Enums\EventPrayerTime;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Enums\TimingMode;
use App\Support\Events\AdminEventTimeMapper;
use Illuminate\Validation\ValidationException;

it('maps custom time fields to starts_at and absolute timing mode', function () {
    $result = AdminEventTimeMapper::normalizeForPersistence([
        'event_date' => '2026-03-20',
        'prayer_time' => EventPrayerTime::LainWaktu->value,
        'custom_time' => '20:15',
        'end_time' => '22:00',
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    expect($result['timing_mode'])->toBe(TimingMode::Absolute->value)
        ->and($result['prayer_reference'])->toBeNull()
        ->and($result['prayer_offset'])->toBeNull()
        ->and($result['starts_at']->toISOString())->toContain('2026-03-20T12:15:00')
        ->and($result['ends_at'])->not->toBeNull();
});

it('maps prayer-relative selection to timing fields and strips helper inputs', function () {
    $result = AdminEventTimeMapper::normalizeForPersistence([
        'event_date' => '2026-03-21',
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    expect($result['timing_mode'])->toBe(TimingMode::PrayerRelative->value)
        ->and($result['prayer_reference'])->toBe(PrayerReference::Maghrib->value)
        ->and($result['prayer_offset'])->toBe(PrayerOffset::Immediately->value)
        ->and($result)->not->toHaveKey('event_date')
        ->and($result)->not->toHaveKey('prayer_time')
        ->and($result)->not->toHaveKey('custom_time')
        ->and($result)->not->toHaveKey('end_time');
});

it('hydrates helper fields from stored event timing fields', function () {
    $result = AdminEventTimeMapper::injectFormTimeFields([
        'starts_at' => '2026-03-28 12:00:00',
        'ends_at' => '2026-03-28 14:00:00',
        'timezone' => 'Asia/Kuala_Lumpur',
        'timing_mode' => TimingMode::PrayerRelative->value,
        'prayer_reference' => PrayerReference::Maghrib->value,
        'prayer_offset' => PrayerOffset::Immediately->value,
    ]);

    expect($result['event_date'])->toBe('2026-03-28')
        ->and($result['custom_time'])->toBe('20:00')
        ->and($result['end_time'])->toBe('22:00')
        ->and($result['prayer_time'])->toBe(EventPrayerTime::SelepasMaghrib->value);
});

it('throws validation exception when end time is before start time', function () {
    AdminEventTimeMapper::normalizeForPersistence([
        'event_date' => '2026-04-10',
        'prayer_time' => EventPrayerTime::LainWaktu->value,
        'custom_time' => '20:00',
        'end_time' => '19:30',
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);
})->throws(ValidationException::class);

it('accepts localized d/m/Y date input for admin form', function () {
    $result = AdminEventTimeMapper::normalizeForPersistence([
        'event_date' => '20/02/2026',
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'end_time' => '09:10 PM',
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    expect($result['starts_at']->toISOString())->toContain('2026-02-20T12:00:00')
        ->and($result['ends_at'])->not->toBeNull();
});

it('interprets ambiguous end time after evening prayer as PM when needed', function () {
    $result = AdminEventTimeMapper::normalizeForPersistence([
        'event_date' => '2026-02-20',
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'end_time' => '09:10',
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);

    expect($result['ends_at']->toISOString())->toContain('2026-02-20T13:10:00');
});
