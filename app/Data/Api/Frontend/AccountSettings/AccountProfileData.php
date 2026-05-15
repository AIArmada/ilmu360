<?php

namespace App\Data\Api\Frontend\AccountSettings;

use App\Data\Api\User\CurrentUserData;
use App\Models\User;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

class AccountProfileData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
        public string $phone,
        public string $timezone,
        public string $daily_prayer_institution_id,
        public string $friday_prayer_institution_id,
        public ?string $email_verified_at,
        public ?string $phone_verified_at,
    ) {}

    public static function fromModel(User $user): self
    {
        $payload = CurrentUserData::fromModel($user)->toArray();

        return new self(
            name: self::stringValue($payload['name'] ?? ''),
            email: self::stringValue($payload['email'] ?? ''),
            phone: self::stringValue($payload['phone'] ?? ''),
            timezone: self::stringValue($payload['timezone'] ?? ''),
            daily_prayer_institution_id: self::stringValue($payload['daily_prayer_institution_id'] ?? ''),
            friday_prayer_institution_id: self::stringValue($payload['friday_prayer_institution_id'] ?? ''),
            email_verified_at: self::optionalDateTimeString($user->email_verified_at),
            phone_verified_at: self::optionalDateTimeString($user->phone_verified_at),
        );
    }

    private static function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function optionalDateTimeString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
