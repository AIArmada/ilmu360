<?php

use AIArmada\Contacting\Enums\SocialPlatform;

it('exposes only supported social media platforms', function () {
    $enumPlatforms = array_map(
        static fn (SocialPlatform $platform): string => $platform->value,
        SocialPlatform::cases(),
    );
    $configuredPlatforms = array_keys(config('contacting.social_profiles.platforms'));

    sort($enumPlatforms);
    sort($configuredPlatforms);

    expect($enumPlatforms)->toBe($configuredPlatforms);
});
