<?php

namespace App\Forms;

use AIArmada\Addressing\Actions\SyncAddressAreaAssignmentsAction;
use AIArmada\Addressing\Contracts\CountryAddressProfile;
use AIArmada\Addressing\Data\AddressLevelDefinition;
use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressAreaRelationship;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use AIArmada\Addressing\Support\AddressAreaStateBridge;
use AIArmada\Addressing\Support\CountryAddressProfileResolver;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\Contacting\Support\SocialProfileConfig;
use App\Actions\Location\NormalizeGoogleMapsInputAction;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Venue;
use App\Support\Cache\SelectionCatalogCache;
use App\Support\Location\AddressAssignments;
use App\Support\Location\LocationSlugResolver;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ysfkaya\FilamentPhoneInput\Forms\PhoneInput;
use Ysfkaya\FilamentPhoneInput\PhoneInputNumberType;

class SharedFormSchema
{
    private static ?string $cacheScope = null;

    /** @var array<string, array<int|string, string>> */
    private static array $countryOptions = [];

    /** @var array<string, array<int|string, string>> */
    private static array $stateOptions = [];

    /** @var array<string, array<int|string, string>> */
    private static array $cityOptions = [];

    /** @var array<string, array<int|string, string>> */
    private static array $areaOptions = [];

    /** @var array<string, array<int|string, string|null>> */
    private static array $areaSlugs = [];

    /** @var array<string, string|null> */
    private static array $countryIdsByState = [];

    /** @var array<string, string|null> */
    private static array $countryIdsByParent = [];

    /** @var list<string>|null */
    private static ?array $entryAreaRoles = null;

    private static function ensureCacheScope(): void
    {
        $scope = spl_object_hash(app()).':'.(app()->bound('request') ? spl_object_hash(request()) : 'console');

        if (self::$cacheScope === $scope) {
            return;
        }

        self::$cacheScope = $scope;
        self::$countryOptions = [];
        self::$stateOptions = [];
        self::$cityOptions = [];
        self::$areaOptions = [];
        self::$areaSlugs = [];
        self::$countryIdsByState = [];
        self::$countryIdsByParent = [];
        self::$entryAreaRoles = null;
    }

    /**
     * Address fields (line1, line2, postcode, regional cascade, maps URLs).
     *
     * Product hierarchy (package-native):
     * - country_id → AddressCountry
     * - state_id → State table
     * - city_id → City table
     * - area_assignments.{role} = country-profile-defined AddressArea selections
     *
     * @return array<int, Component>
     */
    public static function addressFields(
        bool $requireGoogleMaps = false,
        bool $showGoogleMapsUrlField = true,
        bool $enableGoogleMapsNormalization = true,
        bool $enableGoogleMapsRemoteLookup = true,
        bool $includeCountryField = false,
        ?bool $showCountryField = null,
        ?string $defaultCountryId = null,
        bool $requireCountryField = false,
    ): array {
        $showCountryField ??= true;

        return [
            TextInput::make('line1')
                ->label(__('Address Line 1'))
                ->maxLength(255)
                ->placeholder(__('e.g., No. 123, Jalan Masjid')),

            TextInput::make('line2')
                ->label(__('Address Line 2'))
                ->maxLength(255)
                ->placeholder(__('e.g., Taman Indah')),

            TextInput::make('postcode')
                ->label(__('Postcode'))
                ->maxLength(16)
                ->placeholder(__('e.g., 50000')),

            ...self::countryFieldComponents(
                includeCountryField: $includeCountryField,
                showCountryField: $showCountryField,
                defaultCountryId: $defaultCountryId,
                requireCountryField: $requireCountryField,
            ),

            Hidden::make('latitude'),
            Hidden::make('longitude'),
            Hidden::make('provider_place_id'),
            Hidden::make('google_display_name'),
            Hidden::make('google_resolution_source'),
            Hidden::make('google_resolution_status'),
            Hidden::make('google_resolution_fingerprint'),
            Hidden::make('google_resolution_message'),
            Hidden::make('google_maps_normalization_enabled')
                ->default($enableGoogleMapsNormalization),
            Hidden::make('google_maps_remote_lookup_enabled')
                ->default($enableGoogleMapsRemoteLookup),
            Hidden::make('cascade_reset_guard')
                ->default(0)
                ->dehydrated(false),

            ...self::regionalLocationFields(
                includeCountryField: $includeCountryField,
                defaultCountryId: $defaultCountryId,
            ),

            ...($showGoogleMapsUrlField
                ? [self::googleMapsUrlField(required: $requireGoogleMaps)]
                : [Hidden::make('google_maps_url')->required($requireGoogleMaps)]),

            TextInput::make('waze_url')
                ->label(__('Waze URL'))
                ->url()
                ->maxLength(255)
                ->placeholder(__('https://waze.com/ul/...')),
        ];
    }

    public static function addressGroup(
        bool $requireGoogleMaps = false,
        ?string $statePath = null,
        bool $showGoogleMapsUrlField = true,
        bool $enableGoogleMapsNormalization = true,
        bool $enableGoogleMapsRemoteLookup = true,
        bool $includeCountryField = false,
        ?bool $showCountryField = null,
        ?string $defaultCountryId = null,
        bool $requireCountryField = false,
    ): Group {
        $group = Group::make(self::addressFields(
            requireGoogleMaps: $requireGoogleMaps,
            showGoogleMapsUrlField: $showGoogleMapsUrlField,
            enableGoogleMapsNormalization: $enableGoogleMapsNormalization,
            enableGoogleMapsRemoteLookup: $enableGoogleMapsRemoteLookup,
            includeCountryField: $includeCountryField,
            showCountryField: $showCountryField,
            defaultCountryId: $defaultCountryId,
            requireCountryField: $requireCountryField,
        ))
            ->columns(2);

        if ($statePath !== null) {
            $group->statePath($statePath);
        }

        return $group;
    }

    /**
     * Country-plus-region address group for public person submissions.
     */
    public static function regionAddressGroup(
        ?string $statePath = null,
        bool $includeCountryField = false,
        ?bool $showCountryField = null,
        ?string $defaultCountryId = null,
        bool $requireCountryField = false,
    ): Group {
        $group = Group::make(self::regionAddressFields(
            includeCountryField: $includeCountryField,
            showCountryField: $showCountryField,
            defaultCountryId: $defaultCountryId,
            requireCountryField: $requireCountryField,
        ))
            ->columns(2);

        if ($statePath !== null) {
            $group->statePath($statePath);
        }

        return $group;
    }

    /**
     * Country-plus-region address fields for public person submissions.
     *
     * @return array<int, Component>
     */
    public static function regionAddressFields(
        bool $includeCountryField = false,
        ?bool $showCountryField = null,
        ?string $defaultCountryId = null,
        bool $requireCountryField = false,
    ): array {
        $showCountryField ??= true;

        return [
            ...self::countryFieldComponents(
                includeCountryField: $includeCountryField,
                showCountryField: $showCountryField,
                defaultCountryId: $defaultCountryId,
                requireCountryField: $requireCountryField,
            ),
            Hidden::make('cascade_reset_guard')
                ->default(0)
                ->dehydrated(false),
            ...self::regionalLocationFields(
                includeCountryField: $includeCountryField,
                defaultCountryId: $defaultCountryId,
            ),
        ];
    }

    public static function googleMapsUrlField(bool $required = false, ?string $defaultHelperText = null): TextInput
    {
        return TextInput::make('google_maps_url')
            ->label(__('Google Maps URL'))
            ->url()
            ->required($required)
            ->readOnly(fn (Get $get): bool => $get('google_resolution_source') === 'picker' && filled($get('google_maps_url')))
            ->live(onBlur: true)
            ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state): void {
                self::normalizeGoogleMapsFieldState($get, $set, $state, $old);
            })
            ->placeholder(__('https://maps.google.com/...'))
            ->helperText(function (Get $get) use ($defaultHelperText): ?string {
                $message = $get('google_resolution_message');

                if (is_string($message) && $message !== '') {
                    return $message;
                }

                if ($get('google_resolution_source') === 'picker' && filled($get('google_maps_url'))) {
                    return null;
                }

                return $defaultHelperText ?? __('Paste the full Google Maps link from your browser');
            });
    }

    /**
     * Social media repeater schema.
     */
    public static function socialMediaRepeater(string $helperText = 'Add social media links'): Repeater
    {
        return Repeater::make('social_media')
            ->label(__('Social Media'))
            ->schema([
                Grid::make(2)->schema([
                    Select::make('platform')
                        ->label(__('Platform'))
                        ->required()
                        ->options(self::translatedOptions(SocialPlatform::options()))
                        ->searchable()
                        ->live(),
                    TextInput::make('label')
                        ->label(__('Label'))
                        ->maxLength(255)
                        ->helperText(__('Optional name shown alongside this link.'))
                        ->placeholder(__('Main page, Official channel')),
                ]),
                TextInput::make('handle')
                    ->label(__('Handle'))
                    ->required()
                    ->maxLength(255)
                    ->helperText(__('Enter the username or full profile link.'))
                    ->placeholder(__('username / https://...'))
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                        if ($state === null || $state === '' || ! str_contains($state, '://')) {
                            return;
                        }

                        $platform = $get('platform');
                        if ($platform === null || $platform === '') {
                            return;
                        }

                        $platformValue = $platform instanceof SocialPlatform ? $platform->value : $platform;
                        $extracted = app(SocialProfileConfig::class)->extractHandle($platformValue, $state);

                        if ($extracted !== null) {
                            $set('handle', $extracted);
                        }
                    })
                    ->visible(fn (Get $get): bool => self::socialHandleVisible($get)),
                Placeholder::make('profile_url')
                    ->label(__('Profile URL'))
                    ->content(function (Get $get): ?string {
                        $platform = $get('platform');
                        $handle = $get('handle');

                        if (! is_string($handle) || $handle === '') {
                            return null;
                        }

                        $platformValue = $platform instanceof SocialPlatform ? $platform->value : (string) $platform;

                        return app(SocialProfileConfig::class)->buildUrl($platformValue, $handle);
                    })
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => self::socialHandleVisible($get)),
                TextInput::make('url')
                    ->label(__('URL'))
                    ->required()
                    ->url()
                    ->maxLength(255)
                    ->placeholder(__('https://...'))
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => self::socialUrlVisible($get)),
                Grid::make(2)->schema([
                    Toggle::make('is_primary')
                        ->label(__('Primary'))
                        ->fixIndistinctState(),
                    Toggle::make('is_public')
                        ->label(__('Public'))
                        ->default(true),
                ])->columnSpanFull(),
            ])
            ->collapsible()
            ->defaultItems(0)
            ->addActionLabel(__('Add Social Media'))
            ->helperText(__($helperText));
    }

    private static function socialHandleVisible(Get $get): bool
    {
        $platform = $get('platform');
        $value = $platform instanceof SocialPlatform ? $platform->value : $platform;

        return is_string($value)
            && $value !== SocialPlatform::Website->value
            && $value !== SocialPlatform::Other->value;
    }

    private static function socialUrlVisible(Get $get): bool
    {
        $platform = $get('platform');
        $value = $platform instanceof SocialPlatform ? $platform->value : $platform;

        return $value === SocialPlatform::Website->value || $value === SocialPlatform::Other->value;
    }

    public static function contactsRepeater(?string $helperText = null): Repeater
    {
        $repeater = Repeater::make('contactMethods')
            ->label(__('Contact Details'))
            ->default([])
            ->schema([
                Select::make('type')
                    ->label(__('Type'))
                    ->options(self::translatedOptions(ContactMethodType::options()))
                    ->required()
                    ->live(),
                ...self::contactValueFields(),
                Select::make('purpose')
                    ->label(__('Purpose'))
                    ->options(self::translatedOptions(ContactPurpose::options()))
                    ->default(ContactPurpose::General->value)
                    ->required(),
                Grid::make(2)->schema([
                    Toggle::make('is_primary')
                        ->label(__('Primary'))
                        ->fixIndistinctState(),
                    Toggle::make('is_public')
                        ->label(__('Public'))
                        ->default(true),
                ])->columnSpanFull(),
            ])
            ->columns(4)
            ->addActionLabel(__('Add contact details'))
            ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => self::normalizeContactRowsForFill($data))
            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::normalizeContactRowsForSave($data))
            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::normalizeContactRowsForSave($data));

        if ($helperText !== null) {
            $repeater->helperText(__($helperText));
        }

        return $repeater;
    }

    /**
     * @return array<int, TextInput|PhoneInput>
     */
    public static function contactValueFields(): array
    {
        $phoneTypes = [ContactMethodType::Phone, ContactMethodType::Phone->value, ContactMethodType::Whatsapp, ContactMethodType::Whatsapp->value, ContactMethodType::Mobile, ContactMethodType::Mobile->value];
        $emailTypes = [ContactMethodType::Email, ContactMethodType::Email->value];

        return [
            PhoneInput::make('phone_value')
                ->label(fn (Get $get): string => match ($get('type')) {
                    ContactMethodType::Phone, ContactMethodType::Phone->value => __('Phone Number'),
                    ContactMethodType::Whatsapp, ContactMethodType::Whatsapp->value => __('WhatsApp Number'),
                    ContactMethodType::Mobile, ContactMethodType::Mobile->value => __('Mobile Number'),
                    default => __('Value'),
                })
                ->required()
                ->visible(fn (Get $get): bool => in_array($get('type'), $phoneTypes, true))
                ->dehydrated(fn (Get $get): bool => in_array($get('type'), $phoneTypes, true))
                ->afterStateHydrated(function (PhoneInput $component, mixed $state, Get $get, Set $set): void {
                    if (! self::isPhoneContactType($get('type'))) {
                        return;
                    }

                    $value = self::normalizedContactValue($state) ?? self::normalizedContactValue($get('value'));

                    if ($value === null) {
                        return;
                    }

                    $component->state($value);
                    $set('value', $value);
                })
                ->afterStateUpdated(function (Set $set, mixed $state): void {
                    $set('value', self::normalizedContactValue($state));
                })
                ->initialCountry('MY')
                ->displayNumberFormat(PhoneInputNumberType::INTERNATIONAL)
                ->inputNumberFormat(PhoneInputNumberType::E164),
            TextInput::make('value')
                ->label(fn (Get $get): string => match ($get('type')) {
                    ContactMethodType::Email, ContactMethodType::Email->value => __('Email Address'),
                    default => __('Value'),
                })
                ->required()
                ->maxLength(255)
                ->visible(fn (Get $get): bool => ! in_array($get('type'), $phoneTypes, true))
                ->dehydrated(fn (Get $get): bool => ! in_array($get('type'), $phoneTypes, true))
                ->email(fn (Get $get): bool => in_array($get('type'), $emailTypes, true)),
        ];
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $data
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public static function normalizeContactRowsForFill(array $data): array
    {
        if (array_is_list($data)) {
            return array_map(self::normalizeContactRowForFill(...), $data);
        }

        return self::normalizeContactRowForFill($data);
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $data
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public static function normalizeContactRowsForSave(array $data): array
    {
        if (array_is_list($data)) {
            return array_map(self::normalizeContactRowForSave(...), $data);
        }

        return self::normalizeContactRowForSave($data);
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $data
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public static function normalizeContactRowsForComparison(array $data): array
    {
        if (array_is_list($data)) {
            return array_map(self::normalizeContactRowForComparison(...), $data);
        }

        return self::normalizeContactRowForComparison($data);
    }

    /**
     * @param  array<string, mixed>  $contact
     * @return array<string, mixed>
     */
    private static function normalizeContactRowForFill(array $contact): array
    {
        $contact['type'] ??= $contact['category'] ?? null;
        $contact['purpose'] ??= ContactPurpose::General->value;

        unset($contact['category']);

        if (self::isPhoneContactType($contact['type'] ?? null)) {
            $contact['phone_value'] = $contact['value'] ?? null;
        }

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $contact
     * @return array<string, mixed>
     */
    private static function normalizeContactRowForSave(array $contact): array
    {
        $contact['type'] ??= $contact['category'] ?? null;
        $contact['purpose'] ??= ContactPurpose::General->value;
        $type = $contact['type'] ?? null;

        unset($contact['category']);

        if (self::isPhoneContactType($type)) {
            $value = self::normalizedContactValue($contact['phone_value'] ?? $contact['value'] ?? null);

            if ($value !== null) {
                $contact['value'] = $value;
            }

            unset($contact['phone_value']);

            return $contact;
        }

        $value = self::normalizedContactValue($contact['value'] ?? null);

        if ($value !== null) {
            $contact['value'] = $value;
        }

        unset($contact['phone_value']);

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $contact
     * @return array<string, mixed>
     */
    private static function normalizeContactRowForComparison(array $contact): array
    {
        $contact = self::normalizeContactRowForSave($contact);
        $contact = self::normalizeContactRowForFill($contact);

        if (! self::isPhoneContactType($contact['type'] ?? null)) {
            return $contact;
        }

        $value = self::normalizedComparablePhoneValue($contact['phone_value'] ?? ($contact['value'] ?? null));

        if ($value !== null) {
            $contact['phone_value'] = $value;
        }

        unset($contact['value']);

        return $contact;
    }

    public static function isPhoneContactType(mixed $type): bool
    {
        $typeValue = $type instanceof ContactMethodType
            ? $type->value
            : strtolower((string) $type);

        return in_array($typeValue, [ContactMethodType::Phone->value, ContactMethodType::Whatsapp->value, ContactMethodType::Mobile->value], true);
    }

    public static function normalizedContactValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    public static function normalizedComparablePhoneValue(mixed $value): ?string
    {
        $value = self::normalizedContactValue($value);

        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        if (! is_string($digits) || $digits === '') {
            return null;
        }

        return str_starts_with($value, '+') ? '+'.$digits : $digits;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function contactItemLabel(array $state): string
    {
        $type = $state['type'] ?? $state['category'] ?? null;

        if ($type instanceof ContactMethodType) {
            $typeLabel = $type->label();
        } elseif (is_string($type)) {
            $typeLabel = ContactMethodType::tryFrom($type)?->label() ?? $type;
        } else {
            $typeLabel = __('Contact');
        }

        $value = self::isPhoneContactType($type)
            ? self::normalizedContactValue($state['phone_value'] ?? ($state['value'] ?? null))
            : self::normalizedContactValue($state['value'] ?? null);

        return $typeLabel.': '.($value ?? '');
    }

    /**
     * Create address record for a model that has an address() relationship.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createAddressFromData(
        Event|Institution|Person|Venue $model,
        array $data,
        string $type = 'main',
        bool $allowCountryOnly = false,
    ): void {
        $countryProvided = self::countrySelectionProvided($data);
        $data = self::prepareAddressPersistenceData($data);
        $countryId = self::normalizeLocationId($data['country_id'] ?? null);
        $hasAddressContent = collect([
            $data['line1'] ?? null,
            $data['line2'] ?? null,
            $data['postcode'] ?? null,
            $data['state_id'] ?? null,
            $data['city_id'] ?? null,
            $data['area_assignments'] ?? [],
            $data['google_maps_url'] ?? null,
            $data['provider_place_id'] ?? null,
            $data['waze_url'] ?? null,
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
        ])->contains(static fn (mixed $value): bool => filled($value));

        if (! $hasAddressContent && (! $allowCountryOnly || ! $countryProvided && $countryId === null)) {
            return;
        }

        if ($countryId === null && ! $countryProvided && $hasAddressContent) {
            throw ValidationException::withMessages([
                'address.country_id' => __('The address country is required.'),
            ]);
        }

        if ($countryId === null) {
            throw ValidationException::withMessages([
                'address.country_id' => $countryProvided
                    ? __('The selected country is invalid.')
                    : __('The address country is required.'),
            ]);
        }

        $address = Address::query()->create([
            'line1' => $data['line1'] ?? null,
            'line2' => $data['line2'] ?? null,
            'postcode' => $data['postcode'] ?? null,
            'country_id' => $countryId,
            'state_id' => $data['state_id'] ?? null,
            'city_id' => $data['city_id'] ?? null,
            'country' => $data['country'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'state' => $data['state'] ?? null,
            'city' => $data['city'] ?? null,
            'latitude' => isset($data['latitude']) && $data['latitude'] !== '' ? (float) $data['latitude'] : null,
            'longitude' => isset($data['longitude']) && $data['longitude'] !== '' ? (float) $data['longitude'] : null,
            'provider' => 'google',
            'google_maps_url' => $data['google_maps_url'] ?? null,
            'provider_place_id' => $data['provider_place_id'] ?? null,
            'waze_url' => $data['waze_url'] ?? null,
        ]);

        if ($model->exists) {
            $model->addresses()
                ->newPivotStatement()
                ->where('addressable_type', $model->getMorphClass())
                ->where('addressable_id', $model->getKey())
                ->where('type', $type)
                ->delete();

            $model->attachAddress($address, type: $type, isPrimary: true);
        }

        app(SyncAddressAreaAssignmentsAction::class)->execute(
            $address,
            $data['area_assignments'] ?? [],
            $data['state_id'] ?? null,
            ['source' => 'ilmu360'],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeAddressFormState(array $data): array
    {
        if (! self::shouldNormalizeGoogleMaps($data)) {
            return array_merge($data, [
                'provider_place_id' => $data['provider_place_id'] ?? null,
                'google_display_name' => $data['google_display_name'] ?? null,
                'google_resolution_source' => null,
                'google_resolution_status' => null,
                'google_resolution_fingerprint' => null,
                'google_resolution_message' => null,
            ]);
        }

        return array_merge($data, app(NormalizeGoogleMapsInputAction::class)->handle([
            'google_maps_url' => $data['google_maps_url'] ?? null,
            'google_place_id' => $data['provider_place_id'] ?? ($data['google_place_id'] ?? null),
            'country_code' => $data['country_code'] ?? null,
            'google_display_name' => $data['google_display_name'] ?? null,
            'lat' => $data['latitude'] ?? ($data['lat'] ?? null),
            'lng' => $data['longitude'] ?? ($data['lng'] ?? null),
            'google_maps_remote_lookup_enabled' => $data['google_maps_remote_lookup_enabled'] ?? null,
            'google_resolution_source' => $data['google_resolution_source'] ?? null,
            'google_resolution_status' => $data['google_resolution_status'] ?? null,
            'google_resolution_fingerprint' => $data['google_resolution_fingerprint'] ?? null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareAddressPersistenceData(array $data): array
    {
        $normalized = self::normalizeAddressFormState($data);
        $normalized = self::normalizeRegionalSelections($normalized, $data);
        $payload = [];

        if (self::countrySelectionProvided($data)) {
            $payload['country_id'] = self::normalizeCountrySelection($normalized);
        }

        foreach (['state_id', 'city_id'] as $field) {
            if (array_key_exists($field, $data) || array_key_exists($field, $normalized)) {
                $payload[$field] = $normalized[$field] ?? null;
            }
        }

        if (array_key_exists('area_assignments', $data) || array_key_exists('area_assignments', $normalized)) {
            $payload['area_assignments'] = AddressAssignments::normalize((array) ($normalized['area_assignments'] ?? $data['area_assignments'] ?? []));
        }

        foreach (['line1', 'line2', 'postcode', 'state', 'city', 'waze_url'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $normalized[$field] ?? null;
            }
        }

        if (self::hasGoogleMapsInput($data)) {
            $payload['latitude'] = $normalized['lat'] ?? null;
            $payload['longitude'] = $normalized['lng'] ?? null;
            $payload['google_maps_url'] = $normalized['google_maps_url'] ?? null;
            $payload['provider_place_id'] = $normalized['google_place_id'] ?? null;
        }

        return self::hydrateAddressAreaLabels($payload);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function countrySelectionProvided(array $data): bool
    {
        foreach (['country_id', 'country_code', 'country_key'] as $field) {
            $value = $data[$field] ?? null;

            if (is_int($value)) {
                return true;
            }

            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function normalizeCountrySelection(array $data, bool $enabledOnly = false): ?string
    {
        $countryId = self::normalizeLocationId($data['country_id'] ?? null);

        if ($countryId !== null && AddressCountry::query()->whereKey($countryId)->exists()) {
            return $countryId;
        }

        $countryCode = $data['country_code'] ?? null;

        if (is_string($countryCode) && preg_match('/^[A-Za-z]{2}$/', trim($countryCode)) === 1) {
            $resolvedId = AddressCountry::query()
                ->where('iso2', mb_strtoupper(trim($countryCode)))
                ->value('id');

            return is_string($resolvedId) ? $resolvedId : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function hasGoogleMapsInput(array $data): bool
    {
        return array_any([
            'google_maps_url',
            'provider_place_id',
            'google_place_id',
            'google_display_name',
            'latitude',
            'longitude',
            'lat',
            'lng',
        ], fn ($field) => array_key_exists($field, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function hydrateAddressFormState(array $data): array
    {
        $data = self::expandStoredAreasForForm($data);

        $googleMapsUrl = is_string($data['google_maps_url'] ?? null) ? trim($data['google_maps_url']) : null;
        $googlePlaceId = is_string($data['provider_place_id'] ?? ($data['google_place_id'] ?? null))
            ? trim((string) ($data['provider_place_id'] ?? ($data['google_place_id'] ?? null)))
            : null;
        $lat = $data['latitude'] ?? ($data['lat'] ?? null);
        $lng = $data['longitude'] ?? ($data['lng'] ?? null);

        $status = 'unresolved';

        if ($googlePlaceId !== null && $googlePlaceId !== '') {
            $status = 'resolved';
        } elseif (($googleMapsUrl !== null && $googleMapsUrl !== '') || filled($lat) || filled($lng)) {
            $status = 'partial';
        }

        return array_merge($data, [
            'google_display_name' => $data['google_display_name'] ?? null,
            'google_resolution_source' => $data['google_resolution_source'] ?? ($googleMapsUrl !== null ? 'manual' : null),
            'google_resolution_status' => $data['google_resolution_status'] ?? $status,
            'google_resolution_fingerprint' => $data['google_resolution_fingerprint'] ?? ($googleMapsUrl !== null && $googleMapsUrl !== '' ? sha1($googleMapsUrl) : null),
            'google_resolution_message' => $data['google_resolution_message'] ?? null,
        ]);
    }

    private static function normalizeGoogleMapsFieldState(Get $get, Set $set, ?string $state, ?string $old): void
    {
        $currentValue = is_string($state) ? trim($state) : null;
        $oldValue = is_string($old) ? trim($old) : null;

        if ($currentValue === $oldValue) {
            return;
        }

        if (! self::shouldNormalizeGoogleMaps([
            'google_maps_normalization_enabled' => $get('google_maps_normalization_enabled'),
        ])) {
            foreach ([
                'provider_place_id',
                'google_display_name',
                'latitude',
                'longitude',
                'google_resolution_source',
                'google_resolution_status',
                'google_resolution_fingerprint',
                'google_resolution_message',
            ] as $field) {
                $set($field, null);
            }

            return;
        }

        $resolutionFingerprint = $get('google_resolution_fingerprint');

        if (
            (! is_string($resolutionFingerprint) || $resolutionFingerprint === '')
            && is_string($oldValue)
            && $oldValue !== ''
        ) {
            $resolutionFingerprint = sha1($oldValue);
        }

        $normalized = self::normalizeAddressFormState([
            'google_maps_url' => $state,
            'google_place_id' => $get('provider_place_id'),
            'google_display_name' => $get('google_display_name'),
            'lat' => $get('latitude'),
            'lng' => $get('longitude'),
            'google_maps_remote_lookup_enabled' => $get('google_maps_remote_lookup_enabled'),
            'google_resolution_source' => $get('google_resolution_source'),
            'google_resolution_status' => $get('google_resolution_status'),
            'google_resolution_fingerprint' => $resolutionFingerprint,
        ]);

        foreach ([
            'google_maps_url',
            'provider_place_id',
            'google_display_name',
            'latitude',
            'longitude',
            'google_resolution_source',
            'google_resolution_status',
            'google_resolution_fingerprint',
            'google_resolution_message',
        ] as $field) {
            $set($field, match ($field) {
                'provider_place_id' => $normalized['google_place_id'] ?? null,
                'latitude' => $normalized['lat'] ?? null,
                'longitude' => $normalized['lng'] ?? null,
                default => $normalized[$field] ?? null,
            });
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function shouldNormalizeGoogleMaps(array $data): bool
    {
        return filter_var($data['google_maps_normalization_enabled'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * Create social media entries for a model.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createSocialMediaFromData(Institution|Person|Venue|Reference $model, array $data): void
    {
        if (! empty($data['social_media'])) {
            foreach ($data['social_media'] as $index => $social) {
                $model->socialProfiles()->create([
                    'platform' => $social['platform'],
                    'purpose' => ContactPurpose::General->value,
                    'url' => $social['url'] ?? null,
                    'handle' => $social['handle'] ?? $social['username'] ?? null,
                    'sort_order' => is_numeric($social['sort_order'] ?? $social['order_column'] ?? null)
                        ? (int) ($social['sort_order'] ?? $social['order_column'])
                        : $index + 1,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function createContactsFromData(Institution|Person $model, array $data): void
    {
        if (! isset($data['contactMethods']) || ! is_array($data['contactMethods'])) {
            return;
        }

        foreach ($data['contactMethods'] as $index => $contact) {
            if (! is_array($contact)) {
                continue;
            }

            $type = $contact['type'] ?? $contact['category'] ?? null;
            $value = self::isPhoneContactType($type)
                ? ($contact['phone_value'] ?? ($contact['value'] ?? null))
                : ($contact['value'] ?? null);

            if (! filled($type) || ! filled($value)) {
                continue;
            }

            $model->contactMethods()->create([
                'type' => $type,
                'purpose' => $contact['purpose'] ?? ContactPurpose::General->value,
                'value' => self::normalizedContactValue($value) ?? $value,
                'is_public' => (bool) ($contact['is_public'] ?? true),
                'sort_order' => is_numeric($contact['sort_order'] ?? $contact['order_column'] ?? null)
                    ? (int) ($contact['sort_order'] ?? $contact['order_column'])
                    : $index + 1,
            ]);
        }
    }

    /**
     * State options from package `states` table (addresses.state_id).
     *
     * @return array<int|string, string>
     */
    public static function stateOptionsForCountry(int|string|null $countryId): array
    {
        self::ensureCacheScope();
        $countryId = self::normalizeLocationId($countryId);

        if ($countryId === null) {
            return [];
        }

        return self::$stateOptions[$countryId] ??= app(SelectionCatalogCache::class)
            ->rememberAddressOptions("states:{$countryId}", static fn (): array => State::query()
                ->where('country_id', $countryId)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all());
    }

    /**
     * City options from package `cities` table (addresses.city_id).
     *
     * @return array<int|string, string>
     */
    public static function cityOptionsForState(int|string|null $stateId, int|string|null $countryId = null): array
    {
        self::ensureCacheScope();
        $stateId = self::normalizeLocationId($stateId);
        $countryId = self::normalizeLocationId($countryId);

        if ($stateId === null && $countryId === null) {
            return [];
        }

        $cacheKey = ($stateId ?? 'any').':'.($countryId ?? 'any');

        if (array_key_exists($cacheKey, self::$cityOptions)) {
            return self::$cityOptions[$cacheKey];
        }

        $query = City::query();

        if ($stateId !== null) {
            $query->where('state_id', $stateId);
        }

        if ($countryId !== null) {
            $query->where('country_id', $countryId);
        }

        return self::$cityOptions[$cacheKey] = app(SelectionCatalogCache::class)
            ->rememberAddressOptions("cities:{$cacheKey}", static fn (): array => $query
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all());
    }

    /** @return array<int|string, string> */
    public static function districtOptionsForState(int|string|null $stateId, int|string|null $countryId = null): array
    {
        $stateId = self::normalizeLocationId($stateId);
        $countryId = self::normalizeLocationId($countryId);

        if ($stateId !== null) {
            $countryId ??= self::countryIdForState($stateId);
        }

        if ($countryId === null) {
            return [];
        }

        return self::areaOptionsForRole($countryId, 'administrative_district', $stateId);
    }

    /** @return array<int|string, string> */
    public static function subdistrictOptionsForSelection(
        int|string|null $stateId,
        int|string|null $districtId,
        int|string|null $countryId = null,
    ): array {
        $districtId = self::normalizeLocationId($districtId);
        $stateId = self::normalizeLocationId($stateId);
        $countryId = self::normalizeLocationId($countryId) ?? self::countryIdForState($stateId);

        if ($countryId === null) {
            return [];
        }

        return self::areaOptionsForRole($countryId, 'administrative_subdivision', $districtId ?? $stateId);
    }

    public static function shouldShowDistrictField(int|string|null $stateId, int|string|null $countryId = null): bool
    {
        return self::districtOptionsForState($stateId, $countryId) !== [];
    }

    public static function shouldShowSubdistrictField(
        int|string|null $stateId,
        int|string|null $districtId,
        int|string|null $countryId = null,
    ): bool {
        return self::subdistrictOptionsForSelection($stateId, $districtId, $countryId) !== [];
    }

    /**
     * @return array<int|string, string>
     */
    public static function areaOptionsForRole(
        int|string|null $countryId,
        string $role,
        int|string|null $parentId = null,
    ): array {
        self::ensureCacheScope();
        $countryId = self::normalizeLocationId($countryId);

        if ($countryId === null && $parentId !== null) {
            $countryId = self::resolveCountryIdForParent($parentId);
        }

        if ($countryId === null) {
            return [];
        }

        $parentId = self::normalizeLocationId($parentId);
        $cacheKey = "{$countryId}:{$role}:".($parentId ?? 'root');

        if (array_key_exists($cacheKey, self::$areaOptions)) {
            return self::$areaOptions[$cacheKey];
        }

        $query = self::areaQueryForRole($countryId, $role, $parentId);

        if (! $query instanceof Builder) {
            return [];
        }

        return self::$areaOptions[$cacheKey] = app(SelectionCatalogCache::class)
            ->rememberAddressOptions("areas:{$cacheKey}", static fn (): array => $query
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all());
    }

    /**
     * Stored slugs for the same scope `areaOptionsForRole()` lists, used to
     * address areas by slug in friendly URLs. Scopes without options resolve
     * to no slugs, exactly like the options themselves.
     *
     * @return array<int|string, string|null>
     */
    public static function areaSlugsForRole(
        int|string|null $countryId,
        string $role,
        int|string|null $parentId = null,
    ): array {
        self::ensureCacheScope();
        $countryId = self::normalizeLocationId($countryId);

        if ($countryId === null && $parentId !== null) {
            $countryId = self::resolveCountryIdForParent($parentId);
        }

        if ($countryId === null) {
            return [];
        }

        $parentId = self::normalizeLocationId($parentId);
        $cacheKey = "{$countryId}:{$role}:".($parentId ?? 'root');

        if (array_key_exists($cacheKey, self::$areaSlugs)) {
            return self::$areaSlugs[$cacheKey];
        }

        $query = self::areaQueryForRole($countryId, $role, $parentId);

        if (! $query instanceof Builder) {
            return [];
        }

        return self::$areaSlugs[$cacheKey] = app(SelectionCatalogCache::class)
            ->rememberAddressOptions("area-slugs:{$cacheKey}", static fn (): array => $query
                ->orderBy('name')
                ->pluck('slug', 'id')
                ->all());
    }

    /**
     * @return Builder<AddressArea>|null
     */
    private static function areaQueryForRole(string $countryId, string $role, ?string $parentId): ?Builder
    {
        $level = app(CountryAddressProfileResolver::class)->levelForRole($countryId, $role);

        if (! $level instanceof AddressLevelDefinition || $level->kind !== 'area') {
            return null;
        }

        $query = AddressArea::query()->where('country_id', $countryId)->where('is_active', true);

        $areaLevels = $level->areaLevels !== []
            ? $level->areaLevels
            : ($level->areaLevel !== null ? [$level->areaLevel] : []);

        if ($areaLevels !== []) {
            $query->whereIn('level', $areaLevels);
        }

        if ($level->areaTypes !== []) {
            $query->whereIn('type', $level->areaTypes);
        } elseif ($level->areaType !== null) {
            $query->where('type', $level->areaType);
        }

        if ($parentId !== null) {
            $parentAreaId = AddressAreaStateBridge::areaIdForState($parentId, $level->hierarchyType ?? 'administrative');
            $parentAreaId ??= $parentId;
            $hierarchyType = $level->hierarchyType ?? 'administrative';
            $query->whereIn(
                'id',
                AddressAreaRelationship::query()
                    ->where('relationship_type', 'contains')
                    ->where('hierarchy_type', $hierarchyType)
                    ->where('parent_address_area_id', $parentAreaId)
                    ->select('child_address_area_id'),
            );
        } elseif ($level->parentKey !== null) {
            return null;
        }

        return $query;
    }

    private static function countryIdForState(?string $stateId): ?string
    {
        if ($stateId === null) {
            return null;
        }

        self::ensureCacheScope();

        if (array_key_exists($stateId, self::$countryIdsByState)) {
            return self::$countryIdsByState[$stateId];
        }

        $countryId = app(SelectionCatalogCache::class)->rememberAddressValue(
            "country-for-state:{$stateId}",
            static fn (): ?string => State::query()->whereKey($stateId)->value('country_id'),
        );

        return self::$countryIdsByState[$stateId] = is_string($countryId) ? $countryId : null;
    }

    private static function resolveCountryIdForParent(int|string $parentId): ?string
    {
        $parentKey = self::normalizeLocationId($parentId);

        if ($parentKey === null) {
            return null;
        }

        self::ensureCacheScope();

        if (array_key_exists($parentKey, self::$countryIdsByParent)) {
            return self::$countryIdsByParent[$parentKey];
        }

        $countryId = app(SelectionCatalogCache::class)->rememberAddressValue(
            "country-for-parent:{$parentKey}",
            static fn (): ?string => AddressArea::query()->whereKey($parentKey)->value('country_id'),
        );

        if (is_string($countryId) && $countryId !== '') {
            return self::$countryIdsByParent[$parentKey] = $countryId;
        }

        return self::$countryIdsByParent[$parentKey] = self::countryIdForState($parentKey);
    }

    /**
     * Resolve package State id from stored AddressArea ids through explicit package mappings.
     */
    /** @param array<string, mixed> $assignments */
    public static function stateIdFromStoredAreas(array $assignments): ?string
    {
        foreach (AddressAssignments::normalize($assignments) as $areaId) {
            $areaId = self::normalizeLocationId($areaId);

            if ($areaId === null) {
                continue;
            }

            $stateId = AddressAreaStateBridge::stateIdForArea($areaId);

            if ($stateId !== null) {
                return $stateId;
            }
        }

        return null;
    }

    /**
     * Normalize stored address FKs into form state without imposing a country hierarchy.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function expandStoredAreasForForm(array $data): array
    {
        $assignments = AddressAssignments::normalize((array) ($data['area_assignments'] ?? []));
        $stateId = self::normalizeLocationId($data['state_id'] ?? null)
            ?? self::stateIdFromStoredAreas($assignments);
        $cityId = self::normalizeLocationId($data['city_id'] ?? null);

        $countryId = self::normalizeLocationId($data['country_id'] ?? null);

        if ($stateId === null && is_string($data['state'] ?? null) && trim($data['state']) !== '') {
            $stateQuery = State::query()->whereLike('name', trim($data['state']));

            if ($countryId !== null) {
                $stateQuery->where('country_id', $countryId);
            }

            $stateId = self::normalizeLocationId($stateQuery->value('id'));
        }

        if ($cityId === null && is_string($data['city'] ?? null) && trim($data['city']) !== '') {
            $cityQuery = City::query()->whereLike('name', trim($data['city']));

            if ($stateId !== null) {
                $cityQuery->where('state_id', $stateId);
            }

            if ($countryId !== null) {
                $cityQuery->where('country_id', $countryId);
            }

            $cityId = self::normalizeLocationId($cityQuery->value('id'));
        }

        if ($cityId !== null && ! City::query()->whereKey($cityId)->exists()) {
            $cityId = null;
        }

        $data['state_id'] = $stateId;
        $data['city_id'] = $cityId;
        $data['area_assignments'] = $assignments;

        return $data;
    }

    public static function publicLocationPickerCascadeResetGuard(): int
    {
        return 2;
    }

    /**
     * @return array<int, Component>
     */
    private static function countryFieldComponents(
        bool $includeCountryField,
        bool $showCountryField,
        ?string $defaultCountryId,
        bool $requireCountryField,
    ): array {
        if (! $includeCountryField) {
            return [Hidden::make('country_id')->default($defaultCountryId)];
        }

        if (! $showCountryField) {
            return [Hidden::make('country_id')->default($defaultCountryId)];
        }

        return [
            Select::make('country_id')
                ->label(__('Country'))
                ->options(fn (): array => self::countryOptions())
                ->searchable()
                ->preload()
                ->live()
                ->required($requireCountryField)
                ->default($defaultCountryId)
                ->disablePlaceholderSelection($requireCountryField)
                ->afterStateUpdatedJs(self::countryCascadeResetScript())
                ->native(false),
        ];
    }

    /**
     * Filament evaluates options closures more than once during hydration.
     * Countries are a static catalog, so avoid repeating the same query.
     *
     * @return array<int|string, string>
     */
    private static function countryOptions(): array
    {
        self::ensureCacheScope();

        return self::$countryOptions['all'] ??= app(SelectionCatalogCache::class)
            ->rememberAddressOptions('countries', static fn (): array => AddressCountry::query()
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all());
    }

    /**
     * @return array<int, Component>
     */
    private static function regionalLocationFields(bool $includeCountryField, ?string $defaultCountryId): array
    {
        $fields = [
            Select::make('state_id')
                ->label(fn (Get $get): string => self::levelLabel(
                    $includeCountryField ? $get('country_id') : $defaultCountryId,
                    'state_id',
                    __('State / Province'),
                ))
                ->options(fn (Get $get): array => self::stateSelectOptions(
                    $includeCountryField ? $get('country_id') : $defaultCountryId,
                ))
                ->searchable()
                ->preload()
                ->live()
                ->disabled(fn (Get $get): bool => $includeCountryField && self::normalizeLocationId($get('country_id')) === null)
                ->visible(fn (Get $get): bool => self::stateSelectOptions(
                    $includeCountryField ? $get('country_id') : $defaultCountryId,
                ) !== [])
                ->afterStateUpdated(function (Set $set): void {
                    $set('city_id', null);

                    $cleared = [];

                    foreach (self::entryAreaRoles() as $role) {
                        $cleared[$role] = null;
                    }

                    $set('area_assignments', $cleared);
                })
                ->native(false),

            TextInput::make('state')
                ->label(__('State / Province'))
                ->maxLength(255)
                ->visible(fn (Get $get): bool => self::stateSelectOptions(
                    $includeCountryField ? $get('country_id') : $defaultCountryId,
                ) === []),

        ];

        foreach (self::entryAreaRoles() as $role) {
            $fields[] = Select::make("area_assignments.{$role}")
                ->label(fn (Get $get): string => self::levelLabel(
                    $includeCountryField ? $get('country_id') : $defaultCountryId,
                    $role,
                    __('Administrative area'),
                    $get('state_id'),
                    self::formAreaIds($get, $includeCountryField ? $get('country_id') : $defaultCountryId),
                ))
                ->options(fn (Get $get): array => self::areaOptionsForRole(
                    $includeCountryField ? $get('country_id') : $defaultCountryId,
                    $role,
                    self::areaParentIdForRole(
                        $get,
                        $includeCountryField ? $get('country_id') : $defaultCountryId,
                        $role,
                    ),
                ))
                ->searchable()
                ->live()
                ->visible(fn (Get $get): bool => self::areaVisible(
                    $get,
                    $includeCountryField ? $get('country_id') : $defaultCountryId,
                    $role,
                ))
                ->afterStateUpdated(function (Set $set, Get $get) use ($role, $includeCountryField, $defaultCountryId): void {
                    foreach (self::childAreaRoles($includeCountryField ? $get('country_id') : $defaultCountryId, $role) as $child) {
                        $set("area_assignments.{$child}", null);
                    }
                })
                ->native(false);
        }

        $fields = array_merge($fields, [
            Select::make('city_id')
                ->label(__('City'))
                ->options(fn (Get $get): array => self::cityOptionsForState(
                    $get('state_id'),
                    $includeCountryField ? $get('country_id') : $defaultCountryId,
                ))
                ->searchable()
                ->live()
                ->dehydrated(true)
                ->dehydratedWhenHidden(true)
                ->visible(self::cityVisibleClosure(
                    includeCountryField: $includeCountryField,
                    defaultCountryId: $defaultCountryId,
                    isSelect: true,
                ))
                ->afterStateUpdatedJs(self::cityCascadeResetScript())
                ->native(false),

            TextInput::make('city')
                ->label(__('City'))
                ->maxLength(255)
                ->visible(self::cityVisibleClosure(
                    includeCountryField: $includeCountryField,
                    defaultCountryId: $defaultCountryId,
                    isSelect: false,
                )),
        ]);

        return $fields;
    }

    /**
     * Union of every provider's assignment roles for entry-form fields.
     *
     * Fields are built once per request; per-country `visible()` closures
     * reveal only the selected country's roles, so no build-time country is
     * needed. Order follows provider registration with each provider's
     * canonical hierarchy order, so every country's fields render in
     * addressing's cascade order.
     *
     * @return list<string>
     */
    public static function entryAreaRoles(): array
    {
        self::ensureCacheScope();

        if (self::$entryAreaRoles !== null) {
            return self::$entryAreaRoles;
        }

        $roles = [];

        foreach (config('addressing.geography.providers', []) as $providerClass) {
            if (! is_string($providerClass)) {
                continue;
            }

            $provider = app($providerClass);

            if (! $provider instanceof CountryAddressProfile) {
                continue;
            }

            foreach ($provider->addressHierarchies() as $hierarchy) {
                foreach ($hierarchy->levels as $level) {
                    if ($level->kind === 'state') {
                        continue;
                    }

                    $roles[] = CountryAddressProfileResolver::roleForLevel($hierarchy, $level);
                }
            }
        }

        return self::$entryAreaRoles = array_values(array_unique($roles));
    }

    /**
     * @return array<int|string, string>
     */
    private static function stateSelectOptions(mixed $countryId): array
    {
        return app(LocationSlugResolver::class)->stateOptionsForCountry(self::normalizeLocationId($countryId));
    }

    /**
     * Roles whose level sits directly below the given role in the country.
     *
     * @return list<string>
     */
    private static function childAreaRoles(mixed $countryId, string $role): array
    {
        $countryId = self::normalizeLocationId($countryId);

        if ($countryId === null) {
            return [];
        }

        return app(CountryAddressProfileResolver::class)->successorRoles($countryId, $role);
    }

    private static function isFederalTerritory(?string $stateId, ?string $countryId = null): bool
    {
        if ($stateId === null) {
            return false;
        }

        $state = State::query()->whereKey($stateId)->first(['code', 'country_id']);

        if (! $state instanceof State || ! in_array($state->code, ['14', '15', '16'], true)) {
            return false;
        }

        $countryId ??= $state->country_id;

        return is_string($countryId)
            && AddressCountry::query()->whereKey($countryId)->where('iso2', 'MY')->exists();
    }

    private static function cityVisibleClosure(
        bool $includeCountryField,
        ?string $defaultCountryId,
        bool $isSelect,
    ): Closure {
        return function (Get $get) use ($includeCountryField, $defaultCountryId, $isSelect): bool {
            $stateId = $get('state_id');

            if (! filled($stateId)) {
                return false;
            }

            $countryId = self::normalizeLocationId($includeCountryField ? $get('country_id') : $defaultCountryId)
                ?? self::countryIdForState(self::normalizeLocationId($stateId));

            if (self::isFederalTerritory(self::normalizeLocationId($stateId), $countryId)) {
                return false;
            }

            // City is redundant when the country's first cascade slot applies.
            $slot0 = $countryId === null ? null : app(LocationSlugResolver::class)->districtRoleForCountry($countryId);

            if ($slot0 !== null && self::areaOptionsForRole($countryId, $slot0, self::normalizeLocationId($stateId)) !== []) {
                return false;
            }

            $hasCityOptions = self::cityOptionsForState($stateId, $countryId) !== [];

            return $isSelect ? $hasCityOptions : ! $hasCityOptions;
        };
    }

    private static function areaVisible(Get $get, mixed $countryId, string $role): bool
    {
        $countryId = self::normalizeLocationId($countryId) ?? self::countryIdForState(self::normalizeLocationId($get('state_id')));
        $stateId = self::normalizeLocationId($get('state_id'));
        $gate = $countryId === null
            ? null
            : app(LocationSlugResolver::class)->areaEffectiveParentRoleForCountry($countryId, $role, $stateId);

        if ($gate === 'state' && ! filled($stateId)) {
            return false;
        }

        if ($gate !== null && $gate !== 'state' && ! filled($get("area_assignments.{$gate}"))) {
            return false;
        }

        return self::areaOptionsForRole(
            $countryId,
            $role,
            self::areaParentIdForRole($get, $countryId, $role),
        ) !== [];
    }

    private static function areaParentIdForRole(Get $get, mixed $countryId, string $role): mixed
    {
        $countryId = self::normalizeLocationId($countryId);

        if ($countryId === null) {
            return null;
        }

        $areaIds = self::formAreaIds($get, $countryId);

        return app(CountryAddressProfileResolver::class)->parentAreaIdForRole(
            $countryId,
            $role,
            self::normalizeLocationId($get('state_id')),
            $areaIds,
            static fn (string $probeRole, string $probeParentId): bool => self::areaOptionsForRole($countryId, $probeRole, $probeParentId) !== [],
        );
    }

    /**
     * @return array<string, ?string>
     */
    private static function formAreaIds(Get $get, mixed $countryId): array
    {
        $countryId = self::normalizeLocationId($countryId);

        if ($countryId === null) {
            return [];
        }

        $areaIds = [];

        foreach (app(CountryAddressProfileResolver::class)->assignmentRoles($countryId) as $areaRole) {
            $areaIds[$areaRole] = self::normalizeLocationId($get("area_assignments.{$areaRole}"));
        }

        return $areaIds;
    }

    /**
     * @param  array<string, ?string>  $areaIds
     */
    private static function levelLabel(
        mixed $countryId,
        string $role,
        string $fallback,
        mixed $stateId = null,
        array $areaIds = [],
    ): string {
        $countryId = self::normalizeLocationId($countryId);

        if ($countryId === null) {
            return $fallback;
        }

        $label = app(CountryAddressProfileResolver::class)->levelLabel(
            $countryId,
            $role,
            self::normalizeLocationId($stateId),
            $areaIds,
        );

        return $label === null ? $fallback : __($label);
    }

    /**
     * @param  array<int|string, string>  $options
     * @return array<string, string>
     */
    private static function translatedOptions(array $options): array
    {
        $translated = [];

        foreach ($options as $value => $label) {
            $translated[(string) $value] = __($label);
        }

        return $translated;
    }

    /**
     * @param  array<string, ?string>  $areaIds
     */
    public static function locationLevelLabel(
        int|string|null $countryId,
        string $storageColumn,
        string $fallback,
        int|string|null $stateId = null,
        array $areaIds = [],
    ): string {
        return self::levelLabel($countryId, $storageColumn, $fallback, $stateId, $areaIds);
    }

    private static function countryCascadeResetScript(): string
    {
        return <<<'JS'
            const guard = Number($get('cascade_reset_guard') ?? 0)

            if (guard > 0) {
                $set('cascade_reset_guard', guard - 1)
            } else {
                $set('state_id', null)
                $set('city_id', null)
                $set('area_assignments', [])
            }
            JS;
    }

    private static function cityCascadeResetScript(): string
    {
        return <<<'JS'
            // City is independent of district/subdistrict; no child reset.
            JS;
    }

    public static function normalizeLocationId(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return null;
        }

        if (Str::isUuid($trimmed) || ctype_digit($trimmed)) {
            return $trimmed;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $original
     * @return array<string, mixed>
     */
    private static function normalizeRegionalSelections(array $normalized, array $original): array
    {
        $stateId = self::normalizeLocationId($normalized['state_id'] ?? $original['state_id'] ?? null);
        $cityId = self::normalizeLocationId($normalized['city_id'] ?? $original['city_id'] ?? null);
        $assignments = AddressAssignments::normalize((array) ($normalized['area_assignments'] ?? $original['area_assignments'] ?? []));

        $countryId = self::normalizeCountrySelection($normalized)
            ?? self::normalizeCountrySelection($original);

        if ($cityId !== null) {
            $cityQuery = City::query()->whereKey($cityId);

            if ($stateId !== null) {
                $cityQuery->where('state_id', $stateId);
            }

            if ($countryId !== null) {
                $cityQuery->where('country_id', $countryId);
            }

            if (! $cityQuery->exists()) {
                throw ValidationException::withMessages([
                    'address.city_id' => __('The selected city does not belong to the selected state or country.'),
                ]);
            }
        }

        foreach ($assignments as $role => $areaId) {
            $areaQuery = AddressArea::query()->whereKey($areaId);

            if ($countryId !== null) {
                $areaQuery->where('country_id', $countryId);
            }

            if (! $areaQuery->exists()) {
                throw ValidationException::withMessages([
                    'address.area_assignments.'.$role => __('The selected area does not belong to the selected country.'),
                ]);
            }
        }

        if ($stateId !== null) {
            $stateQuery = State::query()->whereKey($stateId);

            if ($countryId !== null) {
                $stateQuery->where('country_id', $countryId);
            }

            if (! $stateQuery->exists()) {
                throw ValidationException::withMessages([
                    'address.state_id' => __('The selected state does not belong to the selected country.'),
                ]);
            }

            $normalized['state_id'] = $stateId;
        }
        if ($cityId !== null) {
            $normalized['city_id'] = $cityId;
        }

        if (array_key_exists('area_assignments', $original) || array_key_exists('area_assignments', $normalized)) {
            $normalized['area_assignments'] = $assignments;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function hydrateAddressAreaLabels(array $payload): array
    {
        $country = isset($payload['country_id']) && is_string($payload['country_id'])
            ? AddressCountry::query()->find($payload['country_id'])
            : null;
        $state = isset($payload['state_id']) && is_string($payload['state_id'])
            ? State::query()->find($payload['state_id'])
            : null;
        $city = isset($payload['city_id']) && is_string($payload['city_id'])
            ? City::query()->find($payload['city_id'])
            : null;
        $areas = collect(AddressAssignments::normalize((array) ($payload['area_assignments'] ?? [])))
            ->map(static fn (string $areaId): ?AddressArea => AddressArea::query()->find($areaId));

        if ($country instanceof AddressCountry) {
            $payload['country'] = $country->name;
            $payload['country_code'] = $country->iso2;
        }

        if ($state instanceof State) {
            $payload['state'] = $state->name;
        }

        if ($city instanceof City) {
            $payload['city'] = $city->name;
        } elseif (empty($payload['city'])) {
            foreach ($areas->reverse() as $area) {
                if ($area instanceof AddressArea) {
                    $payload['city'] = $area->name;
                    break;
                }
            }
        }

        return $payload;
    }
}
