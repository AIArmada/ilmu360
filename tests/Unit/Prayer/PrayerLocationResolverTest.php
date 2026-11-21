<?php

declare(strict_types=1);

use App\Features\Prayer\Api\PrayerLocationData;
use App\Features\Prayer\Api\PrayerLocationResolver;

it('returns immutable prayer geography when event targets have no address', function (): void {
    $location = app(PrayerLocationResolver::class)->forTargets(null, null, 'MY');

    expect($location)->toBeInstanceOf(PrayerLocationData::class)
        ->and($location->countryCode)->toBe('MY')
        ->and($location->latitude)->toBeNull()
        ->and($location->longitude)->toBeNull()
        ->and($location->stateCode)->toBeNull()
        ->and($location->districtCandidates)->toBe([]);
});
