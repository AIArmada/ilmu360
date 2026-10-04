<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Enums\EventPrayerTime;
use App\Jobs\RefreshPrayerTimes;
use App\Models\Institution;
use App\Services\Prayer\PrayerTimesCache;
use App\Support\Events\AdminEventTimeMapper;
use App\Support\Submission\SubmissionTimingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('honors a valid provider clock on submit', function () {
    $startsAt = app(SubmissionTimingPolicy::class)->resolveStartsAt(
        '2026-10-15', EventPrayerTime::SelepasMaghrib->value, null, 'Asia/Kuala_Lumpur', '', '19:07',
    );

    expect($startsAt->setTimezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('19:07');
});

it('degrades a corrupt provider clock to the label estimate on submit', function () {
    $startsAt = app(SubmissionTimingPolicy::class)->resolveStartsAt(
        '2026-10-15', EventPrayerTime::SelepasSubuh->value, null, 'Asia/Kuala_Lumpur', '', 'bogus',
    );

    expect($startsAt->setTimezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('06:30');
});

it('honors a valid provider clock in the admin mapper', function () {
    $data = AdminEventTimeMapper::normalizeForPersistence([
        'event_date' => '2026-10-15',
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'timezone' => 'Asia/Kuala_Lumpur',
        'resolved_start_clock' => '19:07',
    ]);

    expect($data['starts_at']->setTimezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('19:07');
});

it('degrades a corrupt provider clock to the label estimate in the admin mapper', function () {
    $data = AdminEventTimeMapper::normalizeForPersistence([
        'event_date' => '2026-10-15',
        'prayer_time' => EventPrayerTime::SelepasSubuh->value,
        'timezone' => 'Asia/Kuala_Lumpur',
        'resolved_start_clock' => 'bogus',
    ]);

    // SelepasSubuh's own estimate, not the global 20:00 default.
    expect($data['starts_at']->setTimezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('06:30');
});

it('ignores provider data in end-time comparison when providers are disabled', function () {
    Bus::fake();
    config(['prayer.enabled' => false]);

    $institution = OwnerContext::withOwner(null, fn (): Institution => Institution::factory()->create(['status' => 'verified']));
    $formState = [
        'event_date' => '2026-10-15',
        'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        'primary_organizer_id' => (string) $institution->getKey(),
    ];

    // Warm cache that validation must NOT read while disabled.
    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-10', ['2026-10-15' => prayerCacheDto()], 'MY');

    $start = app(SubmissionTimingPolicy::class)->resolveStartTimeForComparison(
        EventPrayerTime::SelepasMaghrib->value, null, null, $formState
    );

    expect($start)->toBe('20:00');

    Bus::assertNotDispatched(RefreshPrayerTimes::class);
});
