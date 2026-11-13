<?php

declare(strict_types=1);

namespace App\Features\Communications\Api;

final readonly class PushDestinationDetails
{
    public function __construct(
        public string $platform,
        public string $fcmToken,
        public string $appVersion,
        public string $deviceLabel,
        public string $locale,
        public string $timezone,
        public ?string $lastSeenAt,
    ) {}
}
