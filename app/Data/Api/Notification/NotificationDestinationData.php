<?php

namespace App\Data\Api\Notification;

use App\Models\NotificationDestination;
use Carbon\CarbonInterface;
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

    public static function fromModel(NotificationDestination $destination): self
    {
        $verifiedAt = $destination->verified_at;

        return new self(
            id: (string) $destination->id,
            installation_id: (string) $destination->address,
            platform: (string) data_get($destination->meta, 'platform', ''),
            device_label: (string) data_get($destination->meta, 'device_label', ''),
            app_version: (string) data_get($destination->meta, 'app_version', ''),
            locale: (string) data_get($destination->meta, 'locale', ''),
            timezone: (string) data_get($destination->meta, 'timezone', ''),
            last_seen_at: (string) data_get($destination->meta, 'last_seen_at', ''),
            verified_at: $verifiedAt instanceof CarbonInterface ? $verifiedAt->toIso8601String() : null,
        );
    }
}
