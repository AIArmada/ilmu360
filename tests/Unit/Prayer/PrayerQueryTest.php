<?php

use App\Data\Prayer\PrayerQuery;

it('normalizes the country code', function () {
    $query = new PrayerQuery('my', '2026-10-15', 'Asia/Kuala_Lumpur');

    expect($query->countryCode)->toBe('MY');
});

it('rejects a non-ISO2 country code', function () {
    new PrayerQuery('MYS', '2026-10-15', 'Asia/Kuala_Lumpur');
})->throws(InvalidArgumentException::class);

it('rejects an invalid date', function (string $date) {
    new PrayerQuery('MY', $date, 'Asia/Kuala_Lumpur');
})->with([
    'wrong shape' => ['15-10-2026'],
    'impossible day' => ['2026-02-31'],
    'relative text' => ['tomorrow'],
])->throws(InvalidArgumentException::class);

it('rejects a missing timezone', function () {
    new PrayerQuery('MY', '2026-10-15', '  ');
})->throws(InvalidArgumentException::class);
