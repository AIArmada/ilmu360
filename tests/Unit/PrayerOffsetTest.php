<?php

use App\Enums\PrayerOffset;

it('maps exact signed minutes to their offset case', function (int $minutes, PrayerOffset $expected) {
    expect(PrayerOffset::fromMinutes($minutes))->toBe($expected);
})->with([
    'before 30' => [-30, PrayerOffset::Before30],
    'before 15' => [-15, PrayerOffset::Before15],
    'immediately' => [5, PrayerOffset::Immediately],
    'after 15' => [15, PrayerOffset::After15],
    'after 30' => [30, PrayerOffset::After30],
    'after 45' => [45, PrayerOffset::After45],
    'after 60' => [60, PrayerOffset::After60],
]);

it('maps unknown signed minutes to the nearest offset case', function (int $minutes, PrayerOffset $expected) {
    expect(PrayerOffset::fromMinutes($minutes))->toBe($expected);
})->with([
    'zero falls just after prayer' => [0, PrayerOffset::Immediately],
    'near immediately' => [7, PrayerOffset::Immediately],
    'tie above immediately' => [10, PrayerOffset::Immediately],
    'near after 15' => [20, PrayerOffset::After15],
    'near after 30' => [37, PrayerOffset::After30],
    'near after 45' => [50, PrayerOffset::After45],
    'far future clamps to after 60' => [100, PrayerOffset::After60],
    'tie below immediately keeps before' => [-5, PrayerOffset::Before15],
    'just before prayer' => [-1, PrayerOffset::Immediately],
    'near before 15' => [-20, PrayerOffset::Before15],
    'far past clamps to before 30' => [-100, PrayerOffset::Before30],
]);
