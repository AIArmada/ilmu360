<?php

declare(strict_types=1);

namespace App\Features\Identity\Api;

final readonly class AccountPreferencesSnapshot
{
    /**
     * @param  list<string>  $dailyPrayerInstitutionIds
     * @param  list<string>  $fridayPrayerInstitutionIds
     */
    public function __construct(
        public array $dailyPrayerInstitutionIds,
        public array $fridayPrayerInstitutionIds,
    ) {}
}
