<?php

namespace App\Livewire\Pages\Dashboard;

use AIArmada\Events\Models\EventSubmission;
use AIArmada\Persons\Enums\Gender;
use App\Enums\NotificationChannel;
use App\Models\Institution;
use App\Models\User;
use App\Platform\Ui\InteractsWithToasts;
use App\Services\Notifications\NotificationSettingsManager;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use LogicException;
use Ysfkaya\FilamentPhoneInput\Forms\PhoneInput;
use Ysfkaya\FilamentPhoneInput\PhoneInputNumberType;

#[Layout('layouts.app')]
class AccountSettings extends Component implements HasForms
{
    use InteractsWithForms;
    use InteractsWithToasts;

    /**
     * @var list<string>
     */
    private const VISIBLE_TRIGGER_KEYS = [
        'reminder_24_hours',
        'reminder_2_hours',
    ];

    #[Url(as: 'tab')]
    public string $tab = 'profile';

    /**
     * @var array{
     *     name: string,
     *     email: string,
     *     phone: string,
     *     timezone: string,
     *     gender: string,
     *     date_of_birth: string,
     *     password: string,
     *     password_confirmation: string,
     *     daily_prayer_institution_ids: list<string>,
     *     friday_prayer_institution_ids: list<string>
     * }
     */
    public array $formData = [
        'name' => '',
        'email' => '',
        'phone' => '',
        'timezone' => '',
        'gender' => '',
        'date_of_birth' => '',
        'password' => '',
        'password_confirmation' => '',
        'daily_prayer_institution_ids' => [],
        'friday_prayer_institution_ids' => [],
    ];

    /**
     * @var array{
     *     quiet_hours_start: string,
     *     quiet_hours_end: string,
     *     channels: list<string>
     * }
     */
    public array $notificationSettingsState = [
        'quiet_hours_start' => '',
        'quiet_hours_end' => '',
        'channels' => ['in_app', 'email'],
    ];

    public bool $quietHoursEnabled = false;

    /**
     * @var array<string, array{enabled: bool, cadence?: string}>
     */
    public array $notificationGroupsState = [
        'discovery' => ['enabled' => true, 'cadence' => 'daily'],
        'my_events' => ['enabled' => true],
        'submission' => ['enabled' => true],
    ];

    /**
     * @var array<string, array{enabled: bool, label: string, description: string}>
     */
    public array $notificationTriggersState = [];

    /**
     * @var array<string, mixed>
     */
    public array $notificationOptions = [];

    /**
     * @var array<string, mixed>
     */
    public array $notificationDestinations = [];

    public bool $showSubmissionGroup = false;

    public function mount(): void
    {
        $user = $this->currentUser();

        $this->tab = $this->normalizeTab($this->tab);
        $this->accountSettingsForm()->fill($this->initialFormData($user));
        $this->hydrateNotificationCenter($user);
    }

    public function updatedTab(string $value): void
    {
        $this->tab = $this->normalizeTab($value);
    }

    public function switchTab(string $tab): void
    {
        $this->tab = $this->normalizeTab($tab);
    }

    public function updatedQuietHoursEnabled(bool $enabled): void
    {
        if (! $enabled) {
            $this->notificationSettingsState['quiet_hours_start'] = '';
            $this->notificationSettingsState['quiet_hours_end'] = '';

            return;
        }

        $this->notificationSettingsState['quiet_hours_start'] = $this->notificationSettingsState['quiet_hours_start'] ?: '22:00';
        $this->notificationSettingsState['quiet_hours_end'] = $this->notificationSettingsState['quiet_hours_end'] ?: '07:00';
    }

    /**
     * @return array<string, string>
     */
    public function timezoneOptions(): array
    {
        return collect(DateTimeZone::listIdentifiers())
            ->mapWithKeys(fn (string $timezone): array => [$timezone => $this->timezoneLabel($timezone)])
            ->all();
    }

    public function form(Schema $schema): Schema
    {
        $user = $this->currentUser();

        return $schema
            ->statePath('formData')
            ->schema([
                Section::make(__('Profile Details'))
                    ->description(__('These details identify you across your account and public interactions.'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Full Name'))
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->mutateStateForValidationUsing(fn (mixed $state): string => trim((string) $state)),
                        TextInput::make('email')
                            ->label(__('Email Address'))
                            ->email()
                            ->requiredWithout('phone')
                            ->maxLength(255)
                            ->helperText(
                                $user->email_verified_at
                                    ? __('Verified email address.')
                                    : __('If you change this address, email verification will need to be completed again.'),
                            )
                            ->mutateStateForValidationUsing(fn (mixed $state): ?string => $this->normalizeOptionalString($state))
                            ->rule(Rule::unique(User::class, 'email')->ignore($user->id)),
                        PhoneInput::make('phone')
                            ->label(__('Phone Number'))
                            ->initialCountry('MY')
                            ->displayNumberFormat(PhoneInputNumberType::INTERNATIONAL)
                            ->inputNumberFormat(PhoneInputNumberType::E164)
                            ->requiredWithout('email')
                            ->helperText(
                                $user->phone_verified_at
                                    ? __('Verified phone number.')
                                    : __('Keep at least one contact method on your account: email or phone.'),
                            )
                            ->mutateStateForValidationUsing(fn (mixed $state): ?string => $this->normalizeOptionalPhone($state))
                            ->rule(Rule::unique(User::class, 'phone')->ignore($user->id)),
                        Select::make('timezone')
                            ->label(__('Preferred Timezone'))
                            ->placeholder(__('Use browser or application default'))
                            ->searchable()
                            ->native(false)
                            ->options($this->timezoneOptions())
                            ->helperText(__('This controls how dates and times are shown to you throughout the application.'))
                            ->columnSpanFull()
                            ->mutateStateForValidationUsing(fn (mixed $state): ?string => $this->normalizeOptionalString($state))
                            ->rule(Rule::in($this->allowedTimezones())),
                        Select::make('gender')
                            ->label(__('Gender'))
                            ->placeholder(__('Select gender'))
                            ->native(false)
                            ->options(collect(Gender::cases())->mapWithKeys(
                                fn (Gender $gender): array => [$gender->value => __($gender->label())],
                            ))
                            ->columnSpan(1)
                            ->mutateStateForValidationUsing(fn (mixed $state): ?string => $this->normalizeOptionalString($state))
                            ->rule(Rule::in(['male', 'female'])),
                        DatePicker::make('date_of_birth')
                            ->label(__('Date of Birth'))
                            ->native(false)
                            ->maxDate(now()->subDay())
                            ->displayFormat('d/m/Y')
                            ->columnSpan(1)
                            ->mutateStateForValidationUsing(fn (mixed $state): ?string => $this->normalizeOptionalString($state))
                            ->rules(['nullable', 'date', 'before:today']),
                        TextInput::make('password')
                            ->label(__('New Password'))
                            ->password()
                            ->autocomplete('new-password')
                            ->confirmed()
                            ->rules(['nullable', 'min:8'])
                            ->mutateStateForValidationUsing(fn (mixed $state): ?string => $this->normalizeOptionalPassword($state))
                            ->maxLength(255),
                        TextInput::make('password_confirmation')
                            ->label(__('Confirm Password'))
                            ->password()
                            ->autocomplete('new-password')
                            ->rules(['nullable'])
                            ->mutateStateForValidationUsing(fn (mixed $state): ?string => $this->normalizeOptionalPassword($state))
                            ->maxLength(255),
                    ]),
                Section::make(__('Prayer Institutions'))
                    ->description(__('Save the institutions you usually attend for daily prayers and Friday prayers. You can select more than one for each.'))
                    ->columns(2)
                    ->schema([
                        Select::make('daily_prayer_institution_ids')
                            ->label(__('Daily Prayer Institutions'))
                            ->placeholder(__('Search institution...'))
                            ->searchable()
                            ->multiple()
                            ->native(false)
                            ->getSearchResultsUsing(fn (string $search): array => $this->searchPrayerInstitutionOptions($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => $this->prayerInstitutionOptionLabel($value))
                            ->getOptionLabelsUsing(fn (array $values): array => $this->prayerInstitutionOptionLabels($values))
                            ->helperText(__('Your usual mosques or suraus for daily prayers.'))
                            ->mutateStateForValidationUsing(fn (mixed $state): array => $this->normalizeOptionalStringArray($state))
                            ->rules(['array']),
                        Select::make('friday_prayer_institution_ids')
                            ->label(__('Friday Prayer Institutions'))
                            ->placeholder(__('Search institution...'))
                            ->searchable()
                            ->multiple()
                            ->native(false)
                            ->getSearchResultsUsing(fn (string $search): array => $this->searchPrayerInstitutionOptions($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => $this->prayerInstitutionOptionLabel($value))
                            ->getOptionLabelsUsing(fn (array $values): array => $this->prayerInstitutionOptionLabels($values))
                            ->helperText(__('Leave blank if you do not want to save separate Friday locations.'))
                            ->mutateStateForValidationUsing(fn (mixed $state): array => $this->normalizeOptionalStringArray($state))
                            ->rules(['array']),
                    ]),
            ]);
    }

    public function saveAccountSettings(): void
    {
        $user = $this->currentUser();
        $validated = $this->accountSettingsForm()->getState();

        $normalizedName = trim((string) ($validated['name'] ?? ''));
        $normalizedEmail = $this->normalizeOptionalString($validated['email'] ?? null);
        $normalizedPhone = $this->normalizeOptionalPhone($validated['phone'] ?? null);
        $normalizedTimezone = $this->normalizeOptionalString($validated['timezone'] ?? null);
        $normalizedGender = $this->normalizeOptionalString($validated['gender'] ?? null);
        $normalizedDateOfBirth = $this->normalizeOptionalString($validated['date_of_birth'] ?? null);
        $normalizedPassword = $this->normalizeOptionalPassword($validated['password'] ?? null);
        $dailyPrayerInstitutionIds = $this->normalizeOptionalStringArray($validated['daily_prayer_institution_ids'] ?? []);
        $fridayPrayerInstitutionIds = $this->normalizeOptionalStringArray($validated['friday_prayer_institution_ids'] ?? []);

        $emailChanged = $normalizedEmail !== $user->email;
        $phoneChanged = $normalizedPhone !== $user->phone;

        $currentDailyPrayerInstitutionIds = $user->dailyPrayerInstitutions()->pluck('institutions.id')->all();
        $currentFridayPrayerInstitutionIds = $user->fridayPrayerInstitutions()->pluck('institutions.id')->all();

        $this->assertPrayerInstitutionSelectionsAllowed(
            $dailyPrayerInstitutionIds,
            $currentDailyPrayerInstitutionIds,
            'formData.daily_prayer_institution_ids',
        );
        $this->assertPrayerInstitutionSelectionsAllowed(
            $fridayPrayerInstitutionIds,
            $currentFridayPrayerInstitutionIds,
            'formData.friday_prayer_institution_ids',
        );

        $forceFill = [
            'name' => $normalizedName,
            'email' => $normalizedEmail,
            'phone' => $normalizedPhone,
            'timezone' => $normalizedTimezone,
            'gender' => $normalizedGender,
            'date_of_birth' => $normalizedDateOfBirth,
            'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
            'phone_verified_at' => $phoneChanged ? null : $user->phone_verified_at,
        ];

        if ($normalizedPassword !== null) {
            $forceFill['password'] = $normalizedPassword;
        }

        try {
            DB::transaction(function () use ($user, $forceFill, $dailyPrayerInstitutionIds, $fridayPrayerInstitutionIds): void {
                $user->forceFill($forceFill)->save();
                $user->dailyPrayerInstitutions()->syncWithPivotValues($dailyPrayerInstitutionIds, ['prayer_type' => 'daily']);
                $user->fridayPrayerInstitutions()->syncWithPivotValues($fridayPrayerInstitutionIds, ['prayer_type' => 'friday']);
            });

            $freshUser = $user->fresh() ?? $user;
            $this->accountSettingsForm()->fill($this->initialFormData($freshUser));
            if (request()->hasSession()) {
                if ($normalizedTimezone !== null) {
                    request()->session()->put('user_timezone', $normalizedTimezone);
                } else {
                    request()->session()->forget('user_timezone');
                }
            }

            $this->settingsManager()->syncProfileSettings($freshUser);

            if ($emailChanged) {
                $freshUser->sendEmailVerificationNotification();
            }

            Notification::make()
                ->success()
                ->title(__('Account settings updated.'))
                ->send();
            $this->hydrateNotificationCenter($freshUser);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Notification::make()
                ->danger()
                ->title(__('Failed to save account settings. Please try again.'))
                ->send();

            report($exception);
        }
    }

    public function saveNotificationPreferences(): void
    {
        $user = $this->currentUser();

        $this->settingsManager()->save($user, $this->notificationPayload());
        $this->hydrateNotificationCenter($user->fresh() ?? $user);

        $this->successToast(__('notifications.flash.updated'));
        $this->tab = 'notifications';
    }

    public function removePushDestination(string $destinationId): void
    {
        $user = $this->currentUser();

        $destination = $user->notificationDestinations()
            ->whereKey($destinationId)
            ->where('channel', NotificationChannel::Push->value)
            ->first();

        abort_unless($destination !== null, 404);

        $destination->delete();

        $this->hydrateNotificationCenter($user->fresh() ?? $user);
        $this->successToast(__('notifications.flash.device_removed'));
        $this->tab = 'notifications';
    }

    protected function hydrateNotificationCenter(User $user): void
    {
        $state = $this->settingsManager()->stateFor($user);

        /** @var array<string, mixed> $settings */
        $settings = $state['settings'];
        $settings['quiet_hours_start'] = $this->timeInputValue((string) ($settings['quiet_hours_start'] ?? ''));
        $settings['quiet_hours_end'] = $this->timeInputValue((string) ($settings['quiet_hours_end'] ?? ''));

        $this->notificationSettingsState = [
            'quiet_hours_start' => (string) ($settings['quiet_hours_start'] ?? ''),
            'quiet_hours_end' => (string) ($settings['quiet_hours_end'] ?? ''),
            'channels' => array_values(array_map('strval', (array) ($settings['channels'] ?? []))),
        ];

        $this->quietHoursEnabled = $this->notificationSettingsState['quiet_hours_start'] !== ''
            || $this->notificationSettingsState['quiet_hours_end'] !== '';

        if ($this->quietHoursEnabled) {
            $this->notificationSettingsState['quiet_hours_start'] = $this->notificationSettingsState['quiet_hours_start'] ?: '22:00';
            $this->notificationSettingsState['quiet_hours_end'] = $this->notificationSettingsState['quiet_hours_end'] ?: '07:00';
        }

        $this->notificationOptions = $state['options'];
        $this->notificationDestinations = $state['destinations'];
        $this->showSubmissionGroup = $this->canSeeSubmissionSettings($user);

        /** @var array<string, array<string, mixed>> $families */
        $families = $state['families'];
        /** @var array<string, array<string, mixed>> $triggers */
        $triggers = $state['triggers'];

        $this->notificationGroupsState = [
            'discovery' => [
                'enabled' => (bool) ($families['followed_content']['enabled'] ?? true),
                'cadence' => (string) ($families['followed_content']['cadence'] ?? 'daily'),
            ],
            'my_events' => [
                'enabled' => (bool) ($families['event_reminders']['enabled'] ?? true),
            ],
            'submission' => [
                'enabled' => (bool) ($families['submission_workflow']['enabled'] ?? true),
            ],
        ];

        $this->notificationTriggersState = [];

        foreach (self::VISIBLE_TRIGGER_KEYS as $triggerKey) {
            $trigger = $triggers[$triggerKey] ?? null;

            if (! is_array($trigger)) {
                continue;
            }

            $this->notificationTriggersState[$triggerKey] = [
                'enabled' => (bool) ($trigger['enabled'] ?? true),
                'label' => (string) ($trigger['label'] ?? ''),
                'description' => (string) ($trigger['description'] ?? ''),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function notificationPayload(): array
    {
        $settings = $this->notificationSettingsState;

        if (! $this->quietHoursEnabled) {
            $settings['quiet_hours_start'] = '';
            $settings['quiet_hours_end'] = '';
        }

        $discoveryEnabled = (bool) ($this->notificationGroupsState['discovery']['enabled'] ?? true);
        $discoveryCadence = (string) ($this->notificationGroupsState['discovery']['cadence'] ?? 'daily');
        $myEventsEnabled = (bool) ($this->notificationGroupsState['my_events']['enabled'] ?? true);
        $submissionEnabled = (bool) ($this->notificationGroupsState['submission']['enabled'] ?? true);

        $families = [
            'followed_content' => ['enabled' => $discoveryEnabled, 'cadence' => $discoveryCadence],
            'saved_search_matches' => ['enabled' => $discoveryEnabled, 'cadence' => $discoveryCadence],
            'event_updates' => ['enabled' => true, 'cadence' => 'instant'],
            'event_reminders' => ['enabled' => $myEventsEnabled, 'cadence' => 'instant'],
            'registration_checkin' => ['enabled' => true, 'cadence' => 'instant'],
            'submission_workflow' => ['enabled' => $submissionEnabled, 'cadence' => 'instant'],
        ];

        $triggers = [];
        foreach (array_keys($this->notificationTriggersState) as $triggerKey) {
            $triggers[$triggerKey] = [
                'enabled' => (bool) ($this->notificationTriggersState[$triggerKey]['enabled'] ?? true),
            ];
        }

        return [
            'settings' => $settings,
            'families' => $families,
            'triggers' => $triggers,
        ];
    }

    protected function timeInputValue(string $value): string
    {
        return mb_strlen($value) >= 5 ? mb_substr($value, 0, 5) : $value;
    }

    /**
     * @return array<int, string>
     */
    protected function allowedTimezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    /**
     * @return array{
     *     name: string,
     *     email: string,
     *     phone: string,
     *     timezone: string,
     *     gender: string,
     *     date_of_birth: string,
     *     password: string,
     *     password_confirmation: string,
     *     daily_prayer_institution_ids: list<string>,
     *     friday_prayer_institution_ids: list<string>
     * }
     */
    protected function initialFormData(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => (string) ($user->email ?? ''),
            'phone' => (string) ($user->phone ?? ''),
            'timezone' => (string) ($user->timezone ?? ''),
            'gender' => (string) ($user->gender ?? ''),
            'date_of_birth' => $user->date_of_birth instanceof CarbonInterface
                ? $user->date_of_birth->format('Y-m-d')
                : '',
            'password' => '',
            'password_confirmation' => '',
            'daily_prayer_institution_ids' => $user->dailyPrayerInstitutions->pluck('id')->values()->all(),
            'friday_prayer_institution_ids' => $user->fridayPrayerInstitutions->pluck('id')->values()->all(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function searchPrayerInstitutionOptions(string $search): array
    {
        $query = $this->selectablePrayerInstitutionQuery();
        $search = trim($search);

        if ($search !== '') {
            $query->searchNameOrNickname($search);
        }

        return $query
            ->orderBy('name')
            ->limit(50)
            ->with('names')->get(['id', 'name'])
            ->mapWithKeys(fn (Institution $institution): array => [(string) $institution->id => $institution->display_name])
            ->all();
    }

    protected function prayerInstitutionOptionLabel(mixed $value): ?string
    {
        $institutionId = $this->normalizeOptionalString($value);

        if ($institutionId === null) {
            return null;
        }

        return Institution::query()
            ->whereKey($institutionId)
            ->with('names')->first(['id', 'name'])
            ?->display_name;
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return array<string, string>
     */
    protected function prayerInstitutionOptionLabels(array $values): array
    {
        $institutionIds = $this->normalizeOptionalStringArray($values);

        if ($institutionIds === []) {
            return [];
        }

        $labels = Institution::query()
            ->whereKey($institutionIds)
            ->with('names')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Institution $institution): array => [(string) $institution->id => $institution->display_name])
            ->all();

        foreach ($institutionIds as $institutionId) {
            $labels[$institutionId] ??= $institutionId;
        }

        return $labels;
    }

    /**
     * @param  list<string>  $selectedInstitutionIds
     * @param  list<string>  $currentInstitutionIds
     */
    protected function assertPrayerInstitutionSelectionsAllowed(array $selectedInstitutionIds, array $currentInstitutionIds, string $errorKey): void
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

    /**
     * @return Builder<Institution>
     */
    protected function selectablePrayerInstitutionQuery(): Builder
    {
        return Institution::query()
            ->active()
            ->where('status', 'verified');
    }

    protected function normalizeOptionalString(mixed $value): ?string
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
    protected function normalizeOptionalStringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', $value),
            static fn (string $item): bool => $item !== '',
        )));
    }

    protected function normalizeOptionalPhone(mixed $value): ?string
    {
        return $this->normalizeOptionalString($value);
    }

    protected function normalizeOptionalPassword(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    protected function timezoneLabel(string $timezone): string
    {
        return sprintf('%s (%s)', $timezone, $this->timezoneOffsetLabel($timezone));
    }

    protected function timezoneOffsetLabel(string $timezone): string
    {
        $offsetSeconds = new DateTimeZone($timezone)->getOffset(new DateTimeImmutable);

        if ($offsetSeconds === 0) {
            return 'GMT';
        }

        $sign = $offsetSeconds < 0 ? '-' : '+';
        $absoluteOffset = abs($offsetSeconds);
        $hours = intdiv($absoluteOffset, 3600);
        $minutes = intdiv($absoluteOffset % 3600, 60);

        return $minutes === 0
            ? sprintf('GMT%s%d', $sign, $hours)
            : sprintf('GMT%s%d:%02d', $sign, $hours, $minutes);
    }

    protected function normalizeTab(string $tab): string
    {
        return in_array($tab, ['profile', 'notifications'], true) ? $tab : 'profile';
    }

    protected function currentUser(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        $freshUser = User::query()->find($user->getKey());

        abort_unless($freshUser instanceof User, 403);

        return $freshUser;
    }

    protected function canSeeSubmissionSettings(User $user): bool
    {
        return $user->hasAhliPanelAccess()
            || EventSubmission::query()
                ->where('submitter_id', $user->getKey())
                ->exists();
    }

    protected function settingsManager(): NotificationSettingsManager
    {
        return app(NotificationSettingsManager::class);
    }

    protected function accountSettingsForm(): Schema
    {
        $schema = $this->getSchema('form');

        if (! $schema instanceof Schema) {
            throw new LogicException('Account settings form schema is not available.');
        }

        return $schema;
    }

    public function render(): View
    {
        return view('livewire.pages.dashboard.account-settings');
    }
}
