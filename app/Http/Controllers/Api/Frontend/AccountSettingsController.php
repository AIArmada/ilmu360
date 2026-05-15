<?php

namespace App\Http\Controllers\Api\Frontend;

use App\Data\Api\Frontend\AccountSettings\AccountProfileData;
use App\Models\Institution;
use App\Models\User;
use App\Services\Notifications\NotificationSettingsManager;
use DateTimeZone;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

#[Group('AccountSettings', 'Authenticated account-settings read and update endpoints for client applications.')]
class AccountSettingsController extends FrontendController
{
    public function __construct(
        private readonly NotificationSettingsManager $notificationSettingsManager,
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
            'daily_prayer_institution_id' => ['nullable', 'uuid'],
            'friday_prayer_institution_id' => ['nullable', 'uuid'],
        ]);

        $nameProvided = $request->exists('name');
        $emailProvided = $request->exists('email');
        $phoneProvided = $request->exists('phone');
        $timezoneProvided = $request->exists('timezone');
        $dailyPrayerInstitutionProvided = $request->exists('daily_prayer_institution_id');
        $fridayPrayerInstitutionProvided = $request->exists('friday_prayer_institution_id');

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
        $dailyPrayerInstitutionId = $dailyPrayerInstitutionProvided
            ? $this->normalizeOptionalString($validated['daily_prayer_institution_id'] ?? null)
            : $user->daily_prayer_institution_id;
        $fridayPrayerInstitutionId = $fridayPrayerInstitutionProvided
            ? $this->normalizeOptionalString($validated['friday_prayer_institution_id'] ?? null)
            : $user->friday_prayer_institution_id;

        if ($normalizedEmail === null && $normalizedPhone === null) {
            throw ValidationException::withMessages([
                'email' => __('Email or phone is required.'),
                'phone' => __('Email or phone is required.'),
            ]);
        }

        $emailChanged = $normalizedEmail !== $user->email;
        $phoneChanged = $normalizedPhone !== $user->phone;

        $this->assertPrayerInstitutionSelectionAllowed(
            $dailyPrayerInstitutionId,
            $user->daily_prayer_institution_id,
            'daily_prayer_institution_id',
        );
        $this->assertPrayerInstitutionSelectionAllowed(
            $fridayPrayerInstitutionId,
            $user->friday_prayer_institution_id,
            'friday_prayer_institution_id',
        );

        $user->forceFill([
            'name' => $normalizedName,
            'email' => $normalizedEmail,
            'phone' => $normalizedPhone,
            'timezone' => $normalizedTimezone,
            'daily_prayer_institution_id' => $dailyPrayerInstitutionId,
            'friday_prayer_institution_id' => $fridayPrayerInstitutionId,
            'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
            'phone_verified_at' => $phoneChanged ? null : $user->phone_verified_at,
        ])->save();

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
            ->where('status', 'verified');
    }

    private function assertPrayerInstitutionSelectionAllowed(?string $selectedInstitutionId, ?string $currentInstitutionId, string $errorKey): void
    {
        if ($selectedInstitutionId === null || $selectedInstitutionId === $currentInstitutionId) {
            return;
        }

        if ($this->selectablePrayerInstitutionQuery()->whereKey($selectedInstitutionId)->exists()) {
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
}
