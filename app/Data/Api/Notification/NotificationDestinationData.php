<?php

namespace App\Data\Api\Notification;

use AIArmada\Communications\Models\CommunicationDestination;
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

    public static function fromModel(CommunicationDestination $destination): self
    {
        $verifiedAt = $destination->verified_at;

        return new self(
            id: (string) $destination->id,
            installation_id: (string) $destination->address,
            platform: (string) data_get($destination->metadata, 'platform', ''),
            device_label: (string) data_get($destination->metadata, 'device_label', ''),
            app_version: (string) data_get($destination->metadata, 'app_version', ''),
            locale: (string) data_get($destination->metadata, 'locale', ''),
            timezone: (string) data_get($destination->metadata, 'timezone', ''),
            last_seen_at: (string) data_get($destination->metadata, 'last_seen_at', ''),
            verified_at: $verifiedAt instanceof CarbonInterface ? $verifiedAt->toIso8601String() : null,
        );
    }
}
