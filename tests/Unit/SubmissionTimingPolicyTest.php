<?php

use App\Enums\EventPrayerTime;
use App\Support\Submission\SubmissionTimingPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('offers zuhur only outside friday', function () {
    $policy = app(SubmissionTimingPolicy::class);

    // 2026-10-02 is a Friday; 2026-10-03 is a Saturday.
    expect($policy->isPrayerTimeAvailable(EventPrayerTime::SelepasZuhur, '2026-10-02', 'Asia/Kuala_Lumpur'))->toBeFalse()
        ->and($policy->isPrayerTimeAvailable(EventPrayerTime::SelepasZuhur, '2026-10-03', 'Asia/Kuala_Lumpur'))->toBeTrue()
        ->and($policy->isPrayerTimeAvailable(EventPrayerTime::SelepasJumaat, '2026-10-02', 'Asia/Kuala_Lumpur'))->toBeTrue();
});

it('rejects zuhur on friday', function () {
    $policy = app(SubmissionTimingPolicy::class);

    $policy->assertPrayerDateIsAllowed(
        EventPrayerTime::SelepasZuhur,
        Carbon::parse('2026-10-03', 'Asia/Kuala_Lumpur'),
        'Asia/Kuala_Lumpur'
    );

    expect(fn () => $policy->assertPrayerDateIsAllowed(
        EventPrayerTime::SelepasZuhur,
        Carbon::parse('2026-10-02', 'Asia/Kuala_Lumpur'),
        'Asia/Kuala_Lumpur'
    ))->toThrow(ValidationException::class);
});
