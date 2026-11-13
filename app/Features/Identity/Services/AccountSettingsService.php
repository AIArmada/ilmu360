<?php

declare(strict_types=1);

namespace App\Features\Identity\Services;

use App\Features\Identity\Api\AccountPreferences;
use App\Features\Identity\Api\PrayerType;
use App\Features\Communications\Api\AccountNotificationPreferences;
use App\Shared\Auth\AccountAccess;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Auth\AccountProfile;
use App\Shared\Auth\AccountProfileUpdate;
use App\Shared\Auth\AccountSettingsStore;
use Illuminate\Support\Facades\DB;

final readonly class AccountSettingsService
{
    public function __construct(
        private AccountDirectory $accounts,
        private AccountSettingsStore $store,
        private AccountPreferences $preferences,
        private AccountNotificationPreferences $notificationPreferences,
        private AccountAccess $access,
    ) {}

    public function read(string $accountId): ?AccountProfile
    {
        return $this->accounts->profile($accountId);
    }

    /**
     * Persist profile fields and prayer preferences, then run the existing
     * notification sync and verification-email side effects.
     *
     * @param  list<string>  $dailyPrayerInstitutionIds
     * @param  list<string>  $fridayPrayerInstitutionIds
     */
    public function update(
        string $accountId,
        AccountProfileUpdate $update,
        array $dailyPrayerInstitutionIds,
        array $fridayPrayerInstitutionIds,
    ): AccountProfile {
        $current = $this->accounts->profile($accountId);

        if (! $current instanceof AccountProfile) {
            throw new \RuntimeException('The account settings profile could not be loaded.');
        }

        DB::transaction(function () use ($accountId, $update, $dailyPrayerInstitutionIds, $fridayPrayerInstitutionIds): void {
            $this->store->updateProfile($accountId, $update);
            $this->preferences->syncPrayerInstitutions($accountId, PrayerType::Daily, $dailyPrayerInstitutionIds);
            $this->preferences->syncPrayerInstitutions($accountId, PrayerType::Friday, $fridayPrayerInstitutionIds);
        });

        $fresh = $this->accounts->profile($accountId)
            ?? throw new \RuntimeException('The updated account settings profile could not be loaded.');

        $this->notificationPreferences->syncProfileSettings($accountId);

        if ($update->email !== $current->email) {
            $this->access->sendEmailVerification($accountId);
        }

        return $fresh;
    }
}
