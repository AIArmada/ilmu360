<?php

declare(strict_types=1);

namespace App\Features\Communications\Api;

final readonly class PushDestinationSnapshot
{
    public function __construct(
        public string $id,
        public string $installationId,
        public ?string $fcmToken,
        public string $platform,
        public string $appVersion,
        public string $deviceLabel,
        public string $locale,
        public string $timezone,
        public ?string $lastSeenAt,
        public ?string $verifiedAt,
    ) {}
}
