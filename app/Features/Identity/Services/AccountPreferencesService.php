<?php

declare(strict_types=1);

namespace App\Features\Identity\Services;

use App\Features\Identity\Actions\SyncPrayerInstitutionsAction;
use App\Features\Identity\Api\AccountPreferences;
use App\Features\Identity\Api\AccountPreferencesSnapshot;
use App\Features\Identity\Api\PrayerType;
use App\Features\Identity\Queries\UserPrayerPreferences;

final readonly class AccountPreferencesService implements AccountPreferences
{
    public function __construct(
        private UserPrayerPreferences $prayerPreferences,
        private SyncPrayerInstitutionsAction $syncPrayerInstitutions,
    ) {}

    public function read(string $accountId): AccountPreferencesSnapshot
    {
        return new AccountPreferencesSnapshot(
            dailyPrayerInstitutionIds: $this->prayerPreferences->institutionIds($accountId, PrayerType::Daily),
            fridayPrayerInstitutionIds: $this->prayerPreferences->institutionIds($accountId, PrayerType::Friday),
        );
    }

    public function syncPrayerInstitutions(string $accountId, PrayerType $prayerType, array $institutionIds): void
    {
        $this->syncPrayerInstitutions->handle($accountId, $prayerType, $institutionIds);
    }
}
