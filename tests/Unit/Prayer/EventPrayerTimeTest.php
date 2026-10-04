<?php

use App\Enums\EventPrayerTime;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use Tests\TestCase;

uses(TestCase::class);

it('maps sebelum subuh to the fajr anchor fifteen minutes before', function () {
    expect(EventPrayerTime::SebelumSubuh->toPrayerReference())->toBe(PrayerReference::Fajr)
        ->and(EventPrayerTime::SebelumSubuh->getDefaultOffset())->toBe(PrayerOffset::Before15)
        ->and(EventPrayerTime::fromPrayerTiming(PrayerReference::Fajr, PrayerOffset::Before15))->toBe(EventPrayerTime::SebelumSubuh)
        ->and(EventPrayerTime::fromPrayerTiming(PrayerReference::Fajr, PrayerOffset::Immediately))->toBe(EventPrayerTime::SelepasSubuh);
});

it('maps any isha-anchored offset to selepas isyak', function () {
    // Tarawih stores a null anchor (label-only): an Isha anchor never
    // means Tarawih, regardless of the stored offset.
    expect(EventPrayerTime::fromPrayerTiming(PrayerReference::Isha, PrayerOffset::After60))->toBe(EventPrayerTime::SelepasIsyak)
        ->and(EventPrayerTime::fromPrayerTiming(PrayerReference::Isha, PrayerOffset::Immediately))->toBe(EventPrayerTime::SelepasIsyak);
});

it('maps selepas tarawih to no anchor so filters match by label only', function () {
    // The forward map must agree with null-anchor storage: mapping
    // Tarawih to Isha here would make discovery filters return every
    // Selepas Isyak event for a Tarawih query.
    expect(EventPrayerTime::SelepasTarawih->toPrayerReference())->toBeNull();
});
