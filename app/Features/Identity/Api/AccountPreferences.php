<?php

declare(strict_types=1);

namespace App\Features\Identity\Api;

interface AccountPreferences
{
    /**
     * Read the account's prayer-institution preferences.
     */
    public function read(string $accountId): AccountPreferencesSnapshot;

    /**
     * Replace one prayer preference set with the supplied institution ids.
     *
     * Institution ids are stored as opaque UUIDs so restoration relinks the
     * same pivot rows without resolving institution models.
     *
     * @param  list<string>  $institutionIds
     */
    public function syncPrayerInstitutions(string $accountId, PrayerType $prayerType, array $institutionIds): void;
}
