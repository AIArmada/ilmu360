<?php

use App\Shared\Events\Enums\TimingMode;
use App\Shared\Prayer\PrayerOffset;
use App\Shared\Prayer\PrayerReference;
use Carbon\Carbon;

beforeEach(function () {
    // Set up a fake time for consistent testing
    Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00', 'Asia/Kuala_Lumpur'));
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('TimingMode Enum', function () {
    it('has absolute and prayer_relative values', function () {
        expect(TimingMode::Absolute->value)->toBe('absolute')
            ->and(TimingMode::PrayerRelative->value)->toBe('prayer_relative');
    });

    it('has labels in Malay', function () {
        expect(TimingMode::Absolute->label())->toBe('Masa Tepat')
            ->and(TimingMode::PrayerRelative->label())->toBe('Waktu Solat');
    });
});

describe('PrayerReference Enum', function () {
    it('has all five daily prayers', function () {
        expect(PrayerReference::cases())->toHaveCount(6)
            ->and(PrayerReference::Fajr->value)->toBe('fajr')
            ->and(PrayerReference::Dhuhr->value)->toBe('dhuhr')
            ->and(PrayerReference::Asr->value)->toBe('asr')
            ->and(PrayerReference::Maghrib->value)->toBe('maghrib')
            ->and(PrayerReference::Isha->value)->toBe('isha')
            ->and(PrayerReference::FridayPrayer->value)->toBe('friday_prayer');
    });

    it('has Malay labels', function () {
        expect(PrayerReference::Fajr->label())->toBe('Subuh')
            ->and(PrayerReference::Dhuhr->label())->toBe('Zuhur')
            ->and(PrayerReference::Maghrib->label())->toBe('Maghrib');
    });

    it('maps to Aladhan API keys', function () {
        expect(PrayerReference::Fajr->aladhanKey())->toBe('Fajr')
            ->and(PrayerReference::FridayPrayer->aladhanKey())->toBe('Dhuhr');
    });
});

describe('PrayerOffset Enum', function () {
    it('has common offset values', function () {
        expect(PrayerOffset::Before30->minutes())->toBe(-30)
            ->and(PrayerOffset::Before15->minutes())->toBe(-15)
            ->and(PrayerOffset::Immediately->minutes())->toBe(5)
            ->and(PrayerOffset::After15->minutes())->toBe(15)
            ->and(PrayerOffset::After30->minutes())->toBe(30);
    });

    it('generates display text', function () {
        expect(PrayerOffset::Immediately->displayText(PrayerReference::Maghrib))
            ->toBe('Selepas Maghrib')
            ->and(PrayerOffset::After30->displayText(PrayerReference::Isha))->toBe('30 minit selepas Isyak')
            ->and(PrayerOffset::Before15->displayText(PrayerReference::Fajr))->toBe('15 minit sebelum Subuh');
    });
});
