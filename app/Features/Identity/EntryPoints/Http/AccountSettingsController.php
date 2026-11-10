<?php

namespace App\Features\Identity\EntryPoints\Http;

use App\Features\Identity\Api\AccountPreferences;
use App\Features\Identity\EntryPoints\Http\Data\AccountProfileData;
use App\Features\Identity\Services\AccountSettingsService;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Auth\AccountProfileUpdate;
use App\Shared\Auth\AccountRecord;
use App\Shared\Directory\PrayerInstitutionCatalog;
use DateTimeZone;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

#[Group('AccountSettings', 'Authenticated account-settings read and update endpoints for client applications.')]
class AccountSettingsController extends Controller
{
    public function __construct(
        private readonly AccountSettingsService $accountSettings,
        private readonly AccountPreferences $accountPreferences,
        private readonly AccountDirectory $accounts,
        private readonly PrayerInstitutionCatalog $prayerInstitutions,
    ) {}

    #[Endpoint(
        title: 'Get account settings',
        description: 'Returns the current authenticated user\'s account profile settings payload.',
    )]
    public function show(Request $request): JsonResponse
    {
        $accountId = $this->requireAccountId($request);

        return response()->json([
            'data' => [
                'profile' => AccountProfileData::fromRecord(
                    $this->record($accountId),
                    $this->accountPreferences->read($accountId),
                )->toArray(),
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
        $accountId = $this->requireAccountId($request);
        $current = $this->accountSettings->read($accountId);

        if ($current === null) {
            abort(401);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($accountId)],
            'phone' => ['nullable', 'string', Rule::unique('users', 'phone')->ignore($accountId)],
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
            : $current->name;
        $normalizedEmail = $emailProvided
            ? $this->normalizeOptionalString($validated['email'] ?? null)
            : $current->email;
        $normalizedPhone = $phoneProvided
            ? $this->normalizeOptionalString($validated['phone'] ?? null)
            : $current->phone;
        $normalizedTimezone = $timezoneProvided
            ? $this->normalizeOptionalString($validated['timezone'] ?? null)
            : $current->rawTimezone;
        $preferences = $this->accountPreferences->read($accountId);
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

        $this->accountSettings->update(
            $accountId,
            new AccountProfileUpdate(
                name: $normalizedName,
                email: $normalizedEmail,
                phone: $normalizedPhone,
                timezone: $normalizedTimezone,
            ),
            $dailyPrayerInstitutionIds,
            $fridayPrayerInstitutionIds,
        );

        return response()->json([
            'message' => __('Account settings updated.'),
            'data' => [
                'profile' => AccountProfileData::fromRecord(
                    $this->record($accountId),
                    $this->accountPreferences->read($accountId),
                )->toArray(),
            ],
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ]);
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

        if (count($this->prayerInstitutions->selectableIds($newInstitutionIds)) === count($newInstitutionIds)) {
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

    private function requireAccountId(Request $request): string
    {
        $user = $request->user('sanctum');

        abort_unless($user instanceof Authenticatable, 401);

        return (string) $user->getAuthIdentifier();
    }

    private function record(string $accountId): AccountRecord
    {
        return $this->accounts->record($accountId) ?? abort(401);
    }

    private function requestId(Request $request): string
    {
        return $request->header('X-Request-ID', (string) Str::uuid());
    }
}
