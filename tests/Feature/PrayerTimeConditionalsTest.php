<?php

declare(strict_types=1);

use App\Features\Events\EntryPoints\Livewire\Pages\SubmitEvent\Create;
use App\Features\Prayer\Api\EventPrayerTime;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-02-01 07:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('base prayer time options are visible without date selected', function () {
    $component = Livewire::test(Create::class);

    assertSubmitEventPrayerOptions(
        $component,
        [
            EventPrayerTime::SelepasSubuh,
            EventPrayerTime::SelepasZuhur,
            EventPrayerTime::SelepasAsar,
            EventPrayerTime::SelepasMaghrib,
            EventPrayerTime::SelepasIsyak,
            EventPrayerTime::LainWaktu,
        ],
        [
            EventPrayerTime::SebelumJumaat,
            EventPrayerTime::SelepasJumaat,
            EventPrayerTime::SelepasTarawih,
        ],
    );
});

test('selepas jumaat appears on friday', function () {
    // Feb 6, 2026 is a Friday
    assertSubmitEventPrayerOptions(
        Livewire::test(Create::class)->set('data.event_date', '2026-02-06'),
        [EventPrayerTime::SebelumJumaat, EventPrayerTime::SelepasJumaat],
    );
});

test('selepas jumaat does not appear on non-friday', function () {
    // Feb 5, 2026 is a Thursday
    assertSubmitEventPrayerOptions(
        Livewire::test(Create::class)->set('data.event_date', '2026-02-05'),
        [],
        [EventPrayerTime::SebelumJumaat, EventPrayerTime::SelepasJumaat],
    );
});

test('selepas tarawih appears during ramadhan', function () {
    // Feb 25, 2026 is during Ramadhan (Feb 18 - Mar 19, 2026)
    assertSubmitEventPrayerOptions(
        Livewire::test(Create::class)->set('data.event_date', '2026-02-25'),
        [EventPrayerTime::SebelumMaghrib, EventPrayerTime::SelepasTarawih],
    );
});

test('selepas tarawih does not appear outside ramadhan', function () {
    // Apr 1, 2026 is after Ramadhan
    assertSubmitEventPrayerOptions(
        Livewire::test(Create::class)->set('data.event_date', '2026-04-01'),
        [EventPrayerTime::SebelumMaghrib],
        [EventPrayerTime::SelepasTarawih],
    );
});

test('both selepas jumaat and tarawih appear on friday during ramadhan', function () {
    // Feb 27, 2026 is a Friday during Ramadhan
    assertSubmitEventPrayerOptions(
        Livewire::test(Create::class)->set('data.event_date', '2026-02-27'),
        [EventPrayerTime::SelepasJumaat, EventPrayerTime::SelepasTarawih],
    );
});

test('zohor is now spelled as zuhur', function () {
    $options = submitEventFieldOptions(
        Livewire::test(Create::class)->set('data.event_date', '2026-03-01'),
        'prayer_time',
    );

    expect($options)
        ->toHaveKey(EventPrayerTime::SelepasZuhur->value)
        ->and($options[EventPrayerTime::SelepasZuhur->value])->toBe('Selepas Zuhur')
        ->and($options)->not->toContain('Selepas Zohor');
});
