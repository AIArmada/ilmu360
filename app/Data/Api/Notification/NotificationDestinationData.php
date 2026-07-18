<?php

namespace App\Data\Api\Notification;

use AIArmada\Communications\Models\CommunicationDestination;
use Carbon\CarbonImmutable;
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

    private static function timestampToIso(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value)->toIso8601String();
        }

        return null;
    }

    public static function fromModel(CommunicationDestination $destination): self
    {
        return new self(
            id: (string) $destination->id,
            installation_id: (string) $destination->address,
            platform: (string) ($destination->platform ?? ''),
            device_label: (string) ($destination->device_label ?? ''),
            app_version: (string) ($destination->app_version ?? ''),
            locale: (string) ($destination->locale ?? ''),
            timezone: (string) ($destination->timezone ?? ''),
            last_seen_at: self::timestampToIso($destination->last_seen_at) ?? '',
            verified_at: self::timestampToIso($destination->verified_at),
        );
    }
}
