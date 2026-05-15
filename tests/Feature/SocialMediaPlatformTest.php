<?php

use App\Enums\SocialMediaPlatform;

it('exposes only supported social media platforms', function () {
    expect(array_map(
        static fn (SocialMediaPlatform $platform): string => $platform->value,
        SocialMediaPlatform::cases(),
    ))->toBe([
        'facebook',
        'twitter',
        'instagram',
        'youtube',
        'tiktok',
        'telegram',
        'whatsapp',
        'linkedin',
        'wikipedia',
        'website',
        'threads',
        'other',
    ]);
});
