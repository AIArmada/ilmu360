<?php

use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Enums\TimingMode;
use App\Services\PrayerTimeService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Set up a fake time for consistent testing
    Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00', 'Asia/Kuala_Lumpur'));
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('TimingMode Enum', function () {
    it('has absolute and prayer_relative values', function () {
        expect(TimingMode::Absolute->value)->toBe('absolute');
        expect(TimingMode::PrayerRelative->value)->toBe('prayer_relative');
    });

    it('has labels in Malay', function () {
        expect(TimingMode::Absolute->label())->toBe('Masa Tepat');
        expect(TimingMode::PrayerRelative->label())->toBe('Waktu Solat');
    });
});

describe('PrayerReference Enum', function () {
    it('has all five daily prayers', function () {
        expect(PrayerReference::cases())->toHaveCount(6);
        expect(PrayerReference::Fajr->value)->toBe('fajr');
        expect(PrayerReference::Dhuhr->value)->toBe('dhuhr');
        expect(PrayerReference::Asr->value)->toBe('asr');
        expect(PrayerReference::Maghrib->value)->toBe('maghrib');
        expect(PrayerReference::Isha->value)->toBe('isha');
        expect(PrayerReference::FridayPrayer->value)->toBe('friday_prayer');
    });

    it('has Malay labels', function () {
        expect(PrayerReference::Fajr->label())->toBe('Subuh');
        expect(PrayerReference::Dhuhr->label())->toBe('Zuhur');
        expect(PrayerReference::Maghrib->label())->toBe('Maghrib');
    });

    it('maps to Aladhan API keys', function () {
        expect(PrayerReference::Fajr->aladhanKey())->toBe('Fajr');
        expect(PrayerReference::FridayPrayer->aladhanKey())->toBe('Dhuhr');
    });
});

describe('PrayerOffset Enum', function () {
    it('has common offset values', function () {
        expect(PrayerOffset::Before30->minutes())->toBe(-30);
        expect(PrayerOffset::Before15->minutes())->toBe(-15);
        expect(PrayerOffset::Immediately->minutes())->toBe(5);
        expect(PrayerOffset::After15->minutes())->toBe(15);
        expect(PrayerOffset::After30->minutes())->toBe(30);
    });

    it('generates display text', function () {
        expect(PrayerOffset::Immediately->displayText(PrayerReference::Maghrib))
            ->toBe('Selepas Maghrib');

        expect(PrayerOffset::After30->displayText(PrayerReference::Isha))
            ->toBe('30 minit selepas Isyak');

        expect(PrayerOffset::Before15->displayText(PrayerReference::Fajr))
            ->toBe('15 minit sebelum Subuh');
    });
});

describe('PrayerTimeService', function () {
    it('fetches prayer times from Aladhan API', function () {
        Http::fake([
            'api.aladhan.com/*' => Http::response([
                'data' => [
                    'timings' => [
                        'Fajr' => '05:55',
                        'Dhuhr' => '13:15',
                        'Asr' => '16:40',
                        'Maghrib' => '19:20',
                        'Isha' => '20:35',
                    ],
                ],
            ]),
        ]);

        $service = new PrayerTimeService;
        $prayerTimes = $service->getPrayerTimes(
            Carbon::parse('2026-01-15'),
            3.1390,
            101.6869,
        );

        expect($prayerTimes)->toBeArray();
        expect($prayerTimes)->toHaveKeys(['Fajr', 'Dhuhr', 'Asr', 'Maghrib', 'Isha']);
        expect($prayerTimes['Maghrib']->format('H:i'))->toBe('19:20');
    });

    it('calculates start time with offset', function () {
        Http::fake([
            'api.aladhan.com/*' => Http::response([
                'data' => [
                    'timings' => [
                        'Fajr' => '05:55',
                        'Dhuhr' => '13:15',
                        'Asr' => '16:40',
                        'Maghrib' => '19:20',
                        'Isha' => '20:35',
                    ],
                ],
            ]),
        ]);

        $service = new PrayerTimeService;
        $startTime = $service->calculateStartTime(
            Carbon::parse('2026-01-15'),
            PrayerReference::Maghrib,
            PrayerOffset::After15,
            3.1390,
            101.6869,
        );

        expect($startTime)->not->toBeNull();
        expect($startTime->format('H:i'))->toBe('19:35'); // 19:20 + 15 minutes
    });

    it('handles Immediately offset correctly', function () {
        Http::fake([
            'api.aladhan.com/*' => Http::response([
                'data' => [
                    'timings' => [
                        'Fajr' => '05:55',
                        'Dhuhr' => '13:15',
                        'Asr' => '16:40',
                        'Maghrib' => '19:20',
                        'Isha' => '20:35',
                    ],
                ],
            ]),
        ]);

        $service = new PrayerTimeService;
        $startTime = $service->calculateStartTime(
            Carbon::parse('2026-01-15'),
            PrayerReference::Isha,
            PrayerOffset::Immediately,
            3.1390,
            101.6869,
        );

        expect($startTime)->not->toBeNull();
        expect($startTime->format('H:i'))->toBe('20:40'); // 20:35 + 5 minutes buffer
    });

    it('returns null on API failure', function () {
        Http::fake([
            'api.aladhan.com/*' => Http::response(null, 500),
        ]);

        $service = new PrayerTimeService;
        $prayerTimes = $service->getPrayerTimes(
            Carbon::parse('2026-01-15'),
            3.1390,
            101.6869,
        );

        expect($prayerTimes)->toBeNull();
    });
});
