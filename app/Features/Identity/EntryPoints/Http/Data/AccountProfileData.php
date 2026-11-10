<?php

declare(strict_types=1);

namespace App\Features\Identity\EntryPoints\Http\Data;

use App\Features\Identity\Api\AccountPreferencesSnapshot;
use App\Shared\Auth\AccountRecord;
use DateTimeInterface;
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

    public static function fromRecord(AccountRecord $record, AccountPreferencesSnapshot $preferences): self
    {
        $payload = CurrentUserData::fromRecord($record, $preferences)->toArray();

        return new self(
            name: self::stringValue($payload['name'] ?? ''),
            email: self::stringValue($payload['email'] ?? ''),
            phone: self::stringValue($payload['phone'] ?? ''),
            timezone: self::stringValue($payload['timezone'] ?? ''),
            daily_prayer_institution_ids: self::stringList($preferences->dailyPrayerInstitutionIds),
            friday_prayer_institution_ids: self::stringList($preferences->fridayPrayerInstitutionIds),
            email_verified_at: self::optionalDateTimeString($record->profile->emailVerifiedAt),
            phone_verified_at: self::optionalDateTimeString($record->profile->phoneVerifiedAt),
        );
    }

    private static function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function stringList(array $values): array
    {
        return array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
    }

    private static function optionalDateTimeString(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
