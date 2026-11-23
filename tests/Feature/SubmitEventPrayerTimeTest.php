<?php

declare(strict_types=1);

use App\Features\Events\EntryPoints\Livewire\Pages\SubmitEvent\Create;
use App\Features\Prayer\Api\EventPrayerTime;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-02-01 07:00:00'));

    $this->seed(EventRoleSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('shows base prayer time options without selecting a date', function () {
    // Base options should always be available
    assertSubmitEventPrayerOptions(
        Livewire::test(Create::class),
        [
            EventPrayerTime::SelepasSubuh,
            EventPrayerTime::SelepasZuhur,
            EventPrayerTime::SelepasAsar,
            EventPrayerTime::SelepasMaghrib,
            EventPrayerTime::SelepasIsyak,
            EventPrayerTime::LainWaktu,
        ],
    );
});

it('hides conditional prayer time options without a date', function () {
    // Jumaat and Tarawih require a qualifying date to appear
    assertSubmitEventPrayerOptions(
        Livewire::test(Create::class),
        [EventPrayerTime::SebelumMaghrib],
        [
            EventPrayerTime::SebelumJumaat,
            EventPrayerTime::SelepasJumaat,
            EventPrayerTime::SelepasTarawih,
        ],
    );
});
