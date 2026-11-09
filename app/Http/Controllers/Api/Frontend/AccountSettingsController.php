<?php

namespace App\Http\Controllers\Api\Frontend;

use App\Data\Api\Frontend\AccountSettings\AccountProfileData;
use App\Enums\InstitutionStatus;
use App\Features\Identity\Api\AccountPreferences;
use App\Features\Identity\Api\PrayerType;
use App\Models\Institution;
use App\Models\User;
use App\Services\Notifications\NotificationSettingsManager;
use DateTimeZone;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

#[Group('AccountSettings', 'Authenticated account-settings read and update endpoints for client applications.')]
class AccountSettingsController extends FrontendController
{
    public function __construct(
        private readonly NotificationSettingsManager $notificationSettingsManager,
        private readonly AccountPreferences $accountPreferences,
    ) {}

    #[Endpoint(
        title: 'Get account settings',
        description: 'Returns the current authenticated user\'s account profile settings payload.',
    )]
    public function show(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $user = $user->fresh() ?? $user;

        return response()->json([
            'data' => [
                'profile' => AccountProfileData::fromModel($user)->toArray(),
            ],
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ]);
    }

    #[Endpoint(
        title: 'Update account settings',
        description: 'Updates the current authenticated user\'s profile, timezone, and prayer-institution preferences.',
    )]
    public function update(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $user = $user->fresh() ?? $user;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($user->getKey())],
            'phone' => ['nullable', 'string', Rule::unique(User::class, 'phone')->ignore($user->getKey())],
            'timezone' => ['nullable', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'daily_prayer_institution_ids' => ['nullable', 'array'],
            'daily_prayer_institution_ids.*' => ['uuid'],
            'friday_prayer_institution_ids' => ['nullable', 'array'],
            'friday_prayer_institution_ids.*' => ['uuid'],
        ]);

        $nameProvided = $request->exists('name');
        $emailProvided = $request->exists('email');
        $phoneProvided = $request->exists('phone');
        $timezoneProvided = $request->exists('timezone');
        $dailyPrayerInstitutionsProvided = $request->exists('daily_prayer_institution_ids');
        $fridayPrayerInstitutionsProvided = $request->exists('friday_prayer_institution_ids');

        $normalizedName = $nameProvided
            ? trim((string) ($validated['name'] ?? ''))
            : $user->name;
        $normalizedEmail = $emailProvided
            ? $this->normalizeOptionalString($validated['email'] ?? null)
            : $user->email;
        $normalizedPhone = $phoneProvided
            ? $this->normalizeOptionalString($validated['phone'] ?? null)
            : $user->phone;
        $normalizedTimezone = $timezoneProvided
            ? $this->normalizeOptionalString($validated['timezone'] ?? null)
            : $user->timezone;
        $preferences = $this->accountPreferences->read((string) $user->getKey());
        $currentDailyPrayerInstitutionIds = $preferences->dailyPrayerInstitutionIds;
        $currentFridayPrayerInstitutionIds = $preferences->fridayPrayerInstitutionIds;
        $dailyPrayerInstitutionIds = $dailyPrayerInstitutionsProvided
            ? $this->normalizeOptionalStringArray($validated['daily_prayer_institution_ids'] ?? [])
            : $currentDailyPrayerInstitutionIds;
        $fridayPrayerInstitutionIds = $fridayPrayerInstitutionsProvided
            ? $this->normalizeOptionalStringArray($validated['friday_prayer_institution_ids'] ?? [])
            : $currentFridayPrayerInstitutionIds;

        if ($normalizedEmail === null && $normalizedPhone === null) {
            throw ValidationException::withMessages([
                'email' => __('Email or phone is required.'),
                'phone' => __('Email or phone is required.'),
            ]);
        }

        $emailChanged = $normalizedEmail !== $user->email;
        $phoneChanged = $normalizedPhone !== $user->phone;

        $this->assertPrayerInstitutionSelectionsAllowed(
            $dailyPrayerInstitutionIds,
            $currentDailyPrayerInstitutionIds,
            'daily_prayer_institution_ids',
        );
        $this->assertPrayerInstitutionSelectionsAllowed(
            $fridayPrayerInstitutionIds,
            $currentFridayPrayerInstitutionIds,
            'friday_prayer_institution_ids',
        );

        DB::transaction(function () use ($user, $normalizedName, $normalizedEmail, $normalizedPhone, $normalizedTimezone, $emailChanged, $phoneChanged, $dailyPrayerInstitutionIds, $fridayPrayerInstitutionIds): void {
            $user->forceFill([
                'name' => $normalizedName,
                'email' => $normalizedEmail,
                'phone' => $normalizedPhone,
                'timezone' => $normalizedTimezone,
                'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
                'phone_verified_at' => $phoneChanged ? null : $user->phone_verified_at,
            ])->save();

            $this->accountPreferences->syncPrayerInstitutions((string) $user->getKey(), PrayerType::Daily, $dailyPrayerInstitutionIds);
            $this->accountPreferences->syncPrayerInstitutions((string) $user->getKey(), PrayerType::Friday, $fridayPrayerInstitutionIds);
        });

        $freshUser = $user->fresh() ?? $user;
        $this->notificationSettingsManager->syncProfileSettings($freshUser);

        if ($emailChanged) {
            $freshUser->sendEmailVerificationNotification();
        }

        return response()->json([
            'message' => __('Account settings updated.'),
            'data' => [
                'profile' => AccountProfileData::fromModel($freshUser)->toArray(),
            ],
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ]);
    }

    /**
     * @return Builder<Institution>
     */
    private function selectablePrayerInstitutionQuery(): Builder
    {
        return Institution::query()
            ->active()
            ->where('status', InstitutionStatus::Verified->value);
    }

    /**
     * @param  list<string>  $selectedInstitutionIds
     * @param  list<string>  $currentInstitutionIds
     */
    private function assertPrayerInstitutionSelectionsAllowed(array $selectedInstitutionIds, array $currentInstitutionIds, string $errorKey): void
    {
        $newInstitutionIds = array_values(array_diff($selectedInstitutionIds, $currentInstitutionIds));

        if ($newInstitutionIds === []) {
            return;
        }

        foreach ($newInstitutionIds as $institutionId) {
            if (! Str::isUuid($institutionId)) {
                throw ValidationException::withMessages([
                    $errorKey => __('Please select a valid active institution.'),
                ]);
            }
        }

        $allowedCount = $this->selectablePrayerInstitutionQuery()
            ->whereKey($newInstitutionIds)
            ->count();

        if ($allowedCount === count($newInstitutionIds)) {
            return;
        }

        throw ValidationException::withMessages([
            $errorKey => __('Please select a valid active institution.'),
        ]);
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @return list<string>
     */
    private function normalizeOptionalStringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', $value),
            static fn (string $item): bool => $item !== '',
        )));
    }
}
