<?php

namespace App\Livewire\Pages\Dashboard;

use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\Institution;
use App\Models\User;
use App\Services\Notifications\NotificationSettingsManager;
use App\Support\Notifications\NotificationCatalog;
use DateTimeImmutable;
use DateTimeZone;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
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

    protected bool $syncingInheritedTriggerState = false;

    #[Url(as: 'tab')]
    public string $tab = 'profile';

    /**
     * @var array{
     *     name: string,
     *     email: string,
     *     phone: string,
     *     timezone: string,
     *     password: string,
     *     password_confirmation: string,
     *     daily_prayer_institution_id: string,
     *     friday_prayer_institution_id: string
     * }
     */
    public array $formData = [
        'name' => '',
        'email' => '',
        'phone' => '',
        'timezone' => '',
        'password' => '',
        'password_confirmation' => '',
        'daily_prayer_institution_id' => '',
        'friday_prayer_institution_id' => '',
    ];

    /**
     * @var array<string, mixed>
     */
    public array $notificationSettingsState = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $notificationFamiliesState = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $notificationTriggersState = [];

    /**
     * @var array<string, list<array<string, mixed>>>
     */
    public array $notificationGroupedTriggers = [];

    /**
     * @var array<string, mixed>
     */
    public array $notificationOptions = [];

    /**
     * @var array<string, mixed>
     */
    public array $notificationDestinations = [];

    /**
     * @var array<int, string>
     */
    public array $preferredChannelSlots = ['', '', '', ''];

    /**
     * @var array<int, string>
     */
    public array $fallbackChannelSlots = ['', '', '', ''];

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

    public function updated(string $property, mixed $value): void
    {
        if ($this->syncingInheritedTriggerState) {
            return;
        }

        if (str_starts_with($property, 'notificationFamiliesState.')) {
            $this->syncInheritedTriggerStates();

            return;
        }

        if (
            str_starts_with($property, 'notificationTriggersState.')
            && str_ends_with($property, '.inherits_family')
        ) {
            $this->syncInheritedTriggerStates();
        }
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
                        TextInput::make('password')
                            ->label(__('New Password'))
                            ->password()
                            ->confirmed()
                            ->rules(['nullable', 'min:8'])
                            ->mutateStateForValidationUsing(fn (mixed $state): ?string => $this->normalizeOptionalPassword($state))
                            ->maxLength(255),
                        TextInput::make('password_confirmation')
                            ->label(__('Confirm Password'))
                            ->password()
                            ->rules(['nullable'])
                            ->mutateStateForValidationUsing(fn (mixed $state): ?string => $this->normalizeOptionalPassword($state))
                            ->maxLength(255),
                    ]),
                Section::make(__('Prayer Institutions'))
                    ->description(__('Save the institutions you usually attend for daily prayers and Friday prayers. Friday can be the same as your daily institution.'))
                    ->columns(2)
                    ->schema([
                        Select::make('daily_prayer_institution_id')
                            ->label(__('Daily Prayer Institution'))
                            ->placeholder(__('Search institution...'))
                            ->searchable()
                            ->native(false)
                            ->getSearchResultsUsing(fn (string $search): array => $this->searchPrayerInstitutionOptions($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => $this->prayerInstitutionOptionLabel($value))
                            ->helperText(__('Your usual mosque or surau for daily prayers.'))
                            ->mutateStateForValidationUsing(fn (mixed $state): ?string => $this->normalizeOptionalString($state))
                            ->rules(['nullable', 'uuid']),
                        Select::make('friday_prayer_institution_id')
                            ->label(__('Friday Prayer Institution'))
                            ->placeholder(__('Search institution...'))
                            ->searchable()
                            ->native(false)
                            ->getSearchResultsUsing(fn (string $search): array => $this->searchPrayerInstitutionOptions($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => $this->prayerInstitutionOptionLabel($value))
                            ->helperText(__('Leave blank if you do not want to save a separate Friday location.'))
                            ->mutateStateForValidationUsing(fn (mixed $state): ?string => $this->normalizeOptionalString($state))
                            ->rules(['nullable', 'uuid']),
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
        $normalizedPassword = $this->normalizeOptionalPassword($validated['password'] ?? null);
        $dailyPrayerInstitutionId = $this->normalizeOptionalString($validated['daily_prayer_institution_id'] ?? null);
        $fridayPrayerInstitutionId = $this->normalizeOptionalString($validated['friday_prayer_institution_id'] ?? null);

        $emailChanged = $normalizedEmail !== $user->email;
        $phoneChanged = $normalizedPhone !== $user->phone;

        $this->assertPrayerInstitutionSelectionAllowed(
            $dailyPrayerInstitutionId,
            $user->daily_prayer_institution_id,
            'formData.daily_prayer_institution_id',
        );
        $this->assertPrayerInstitutionSelectionAllowed(
            $fridayPrayerInstitutionId,
            $user->friday_prayer_institution_id,
            'formData.friday_prayer_institution_id',
        );

        $forceFill = [
            'name' => $normalizedName,
            'email' => $normalizedEmail,
            'phone' => $normalizedPhone,
            'timezone' => $normalizedTimezone,
            'daily_prayer_institution_id' => $dailyPrayerInstitutionId,
            'friday_prayer_institution_id' => $fridayPrayerInstitutionId,
            'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
            'phone_verified_at' => $phoneChanged ? null : $user->phone_verified_at,
        ];

        if ($normalizedPassword !== null) {
            $forceFill['password'] = $normalizedPassword;
        }

        $user->forceFill($forceFill)->save();

        $this->accountSettingsForm()->fill($this->initialFormData($user));

        if (request()->hasSession()) {
            if ($normalizedTimezone !== null) {
                request()->session()->put('user_timezone', $normalizedTimezone);
            } else {
                request()->session()->forget('user_timezone');
            }
        }

        $freshUser = $user->fresh() ?? $user;
        $this->settingsManager()->syncProfileSettings($freshUser);

        if ($emailChanged) {
            $freshUser->sendEmailVerificationNotification();
        }

        $this->successToast(__('Account settings updated.'));
        $this->hydrateNotificationCenter($freshUser);
    }

    public function saveNotificationPreferences(): void
    {
        $user = $this->currentUser();

        $this->settingsManager()->save($user, $this->notificationPayload());
        $this->hydrateNotificationCenter($user->fresh());

        $this->successToast(__('notifications.flash.updated'));
        $this->tab = 'notifications';
    }

    protected function hydrateNotificationCenter(User $user): void
    {
        $state = $this->settingsManager()->stateFor($user);

        $this->notificationSettingsState = $state['settings'];
        $this->notificationFamiliesState = $state['families'];
        $this->notificationTriggersState = $state['triggers'];
        $this->notificationGroupedTriggers = $state['grouped_triggers'];
        $this->notificationOptions = $state['options'];
        $this->notificationDestinations = $state['destinations'];
        $this->preferredChannelSlots = array_pad(
            array_values($this->notificationSettingsState['preferred_channels'] ?? []),
            4,
            ''
        );
        $this->fallbackChannelSlots = array_pad(
            array_values($this->notificationSettingsState['fallback_channels'] ?? []),
            4,
            ''
        );
        $this->syncInheritedTriggerStates();
    }

    /**
     * @return array<string, mixed>
     */
    protected function notificationPayload(): array
    {
        $preferredChannels = collect($this->preferredChannelSlots)
            ->map(static fn (mixed $value): string => $value)
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
        $fallbackChannels = collect($this->fallbackChannelSlots)
            ->map(static fn (mixed $value): string => $value)
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();

        $settings = $this->notificationSettingsState;
        $settings['preferred_channels'] = $preferredChannels;
        $settings['fallback_channels'] = $fallbackChannels;
        $settings['timezone'] = (string) ($this->notificationSettingsState['timezone'] ?? config('app.timezone'));

        return [
            'settings' => $settings,
            'families' => $this->notificationFamiliesState,
            'triggers' => $this->notificationTriggersState,
        ];
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
     *     password: string,
     *     password_confirmation: string,
     *     daily_prayer_institution_id: string,
     *     friday_prayer_institution_id: string
     * }
     */
    protected function initialFormData(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => (string) ($user->email ?? ''),
            'phone' => (string) ($user->phone ?? ''),
            'timezone' => (string) ($user->timezone ?? ''),
            'password' => '',
            'password_confirmation' => '',
            'daily_prayer_institution_id' => (string) ($user->daily_prayer_institution_id ?? ''),
            'friday_prayer_institution_id' => (string) ($user->friday_prayer_institution_id ?? ''),
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
            ->get(['id', 'name', 'nickname'])
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
            ->first(['id', 'name', 'nickname'])
            ?->display_name;
    }

    protected function assertPrayerInstitutionSelectionAllowed(?string $selectedInstitutionId, ?string $currentInstitutionId, string $errorKey): void
    {
        if ($selectedInstitutionId === null) {
            return;
        }

        if ($selectedInstitutionId === $currentInstitutionId) {
            return;
        }

        if ($this->selectablePrayerInstitutionQuery()->whereKey($selectedInstitutionId)->exists()) {
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

    protected function databaseLikeOperator(): string
    {
        return config('database.default') === 'pgsql' ? 'ILIKE' : 'LIKE';
    }

    protected function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
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

    protected function syncInheritedTriggerStates(): void
    {
        $this->syncingInheritedTriggerState = true;

        try {
            foreach (NotificationCatalog::triggers() as $triggerKey => $definition) {
                $triggerState = $this->notificationTriggersState[$triggerKey] ?? null;

                if (! is_array($triggerState) || ! (bool) ($triggerState['inherits_family'] ?? true)) {
                    continue;
                }

                $familyKey = (string) ($triggerState['family'] ?? $definition['family']->value);
                $familyState = $this->notificationFamiliesState[$familyKey] ?? [];
                $allowedChannels = is_array($triggerState['allowed_channels'] ?? null)
                    ? $triggerState['allowed_channels']
                    : $definition['allowed_channels'];
                $defaultChannels = array_values(array_intersect($definition['default_channels'], $allowedChannels));
                $familyChannels = collect(is_array($familyState['channels'] ?? null) ? $familyState['channels'] : $defaultChannels)
                    ->map(static fn (mixed $channel): string => (string) $channel)
                    ->filter(static fn (string $channel): bool => in_array($channel, $allowedChannels, true))
                    ->unique()
                    ->values()
                    ->all();

                $this->notificationTriggersState[$triggerKey]['cadence'] = (string) ($familyState['cadence'] ?? $definition['default_cadence']->value);
                $this->notificationTriggersState[$triggerKey]['channels'] = $familyChannels === []
                    ? $defaultChannels
                    : $familyChannels;
                $this->notificationTriggersState[$triggerKey]['urgent_override'] = null;
            }
        } finally {
            $this->syncingInheritedTriggerState = false;
        }
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
