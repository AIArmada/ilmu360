<?php

use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-02-01 07:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('base prayer time options are visible without date selected', function () {
    $component = Livewire::test('pages.submit-event.create');

    $component->assertSee('Selepas Subuh')
        ->assertSee('Selepas Zuhur')
        ->assertSee('Selepas Asar')
        ->assertSee('Selepas Maghrib')
        ->assertSee('Selepas Isyak')
        ->assertSee('Lain Waktu')
        ->assertDontSee('Sebelum Jumaat')
        ->assertDontSee('Selepas Jumaat')
        ->assertDontSee('Sebelum Maghrib')
        ->assertDontSee('Selepas Tarawih');
});

test('selepas jumaat appears on friday', function () {
    // Feb 6, 2026 is a Friday
    Livewire::test('pages.submit-event.create')
        ->set('data.event_date', '2026-02-06')
        ->assertSee('Sebelum Jumaat')
        ->assertSee('Selepas Jumaat');
});

test('selepas jumaat does not appear on non-friday', function () {
    // Feb 5, 2026 is a Thursday
    Livewire::test('pages.submit-event.create')
        ->set('data.event_date', '2026-02-05')
        ->assertDontSee('Sebelum Jumaat')
        ->assertDontSee('Selepas Jumaat');
});

test('selepas tarawih appears during ramadhan', function () {
    // Feb 25, 2026 is during Ramadhan (Feb 18 - Mar 19, 2026)
    Livewire::test('pages.submit-event.create')
        ->set('data.event_date', '2026-02-25')
        ->assertSee('Sebelum Maghrib')
        ->assertSee('Selepas Tarawih');
});

test('selepas tarawih does not appear outside ramadhan', function () {
    // Apr 1, 2026 is after Ramadhan
    Livewire::test('pages.submit-event.create')
        ->set('data.event_date', '2026-04-01')
        ->assertDontSee('Sebelum Maghrib')
        ->assertDontSee('Selepas Tarawih');
});

test('both selepas jumaat and tarawih appear on friday during ramadhan', function () {
    // Feb 27, 2026 is a Friday during Ramadhan
    Livewire::test('pages.submit-event.create')
        ->set('data.event_date', '2026-02-27')
        ->assertSee('Selepas Jumaat')
        ->assertSee('Selepas Tarawih');
});

test('zohor is now spelled as zuhur', function () {
    Livewire::test('pages.submit-event.create')
        ->set('data.event_date', '2026-03-01')
        ->assertSee('Selepas Zuhur')
        ->assertDontSee('Selepas Zohor');
});
