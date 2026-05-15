<?php

use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-02-01 07:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('shows base prayer time options without selecting a date', function () {
    $component = Livewire::test('pages.submit-event.create');

    // Base options should always be visible
    $component->assertSee('Selepas Subuh')
        ->assertSee('Selepas Zuhur')
        ->assertSee('Selepas Asar')
        ->assertSee('Selepas Maghrib')
        ->assertSee('Selepas Isyak')
        ->assertSee('Lain Waktu');
});

it('hides conditional prayer time options without a date', function () {
    $component = Livewire::test('pages.submit-event.create');

    // Jumaat and Tarawih require a qualifying date to appear
    $component->assertDontSee('Sebelum Jumaat')
        ->assertDontSee('Selepas Jumaat')
        ->assertDontSee('Sebelum Maghrib')
        ->assertDontSee('Selepas Tarawih');
});
