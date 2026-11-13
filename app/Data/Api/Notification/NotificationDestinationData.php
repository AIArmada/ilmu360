<?php

namespace App\Data\Api\Notification;

use App\Features\Communications\Api\PushDestinationSnapshot;
use Spatie\LaravelData\Data;

class NotificationDestinationData extends Data
{
    public function __construct(
        public string $id,
        public string $installation_id,
        public string $platform,
        public string $device_label,
        public string $app_version,
        public string $locale,
        public string $timezone,
        public string $last_seen_at,
        public ?string $verified_at,
    ) {}

    public static function fromSnapshot(PushDestinationSnapshot $destination): self
    {
        return new self(
            id: $destination->id,
            installation_id: $destination->installationId,
            platform: $destination->platform,
            device_label: $destination->deviceLabel,
            app_version: $destination->appVersion,
            locale: $destination->locale,
            timezone: $destination->timezone,
            last_seen_at: $destination->lastSeenAt ?? '',
            verified_at: $destination->verifiedAt,
        );
    }
}
