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
        /** @var list<string> */
        public array $daily_prayer_institution_ids,
        /** @var list<string> */
        public array $friday_prayer_institution_ids,
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
            daily_prayer_institution_ids: self::stringList($payload['daily_prayer_institution_ids'] ?? []),
            friday_prayer_institution_ids: self::stringList($payload['friday_prayer_institution_ids'] ?? []),
            email_verified_at: self::optionalDateTimeString($user->email_verified_at),
            phone_verified_at: self::optionalDateTimeString($user->phone_verified_at),
        );
    }

    private static function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    private static function optionalDateTimeString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
