<?php

namespace App\Platform\Ui\Forms;

use AIArmada\Addressing\Data\AddressLevelDefinition;
use AIArmada\Addressing\Features\AddressLifecycle\Support\AddressAssignments;
use AIArmada\Addressing\Features\AddressLifecycle\Support\AddressFormState;
use AIArmada\Addressing\Features\Geography\Models\AddressArea;
use AIArmada\Addressing\Features\Geography\Models\AddressAreaRelationship;
use AIArmada\Addressing\Features\Geography\Models\AddressCountry;
use AIArmada\Addressing\Features\Geography\Models\City;
use AIArmada\Addressing\Features\Geography\Models\State;
use AIArmada\Addressing\Features\Geography\Support\AddressAreaStateBridge;
use AIArmada\Addressing\Support\CountryAddressProfileResolver;
use AIArmada\Addressing\Support\LocationLevelLabels;
use App\Platform\Addressing\AddressAreaRoles;
use App\Platform\Location\LocationSlugResolver;
use App\Shared\Cache\SelectionCatalog;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;

class AddressFields
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
        bool $includeWazeUrlField = true,
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
            Hidden::make('google_place_id'),
            Hidden::make('google_feature_id'),
            Hidden::make('google_cid'),
            Hidden::make('google_entity_id'),
            Hidden::make('google_display_name'),
            Hidden::make('google_resolution_source'),
            Hidden::make('google_resolution_status'),
            Hidden::make('google_resolution_fingerprint'),
            Hidden::make('google_resolution_message'),
            Hidden::make('google_inferred_area_roles')->default([])->dehydrated(false),
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

            ...($includeWazeUrlField ? [
                TextInput::make('waze_url')
                    ->label(__('Waze URL'))
                    ->url()
                    ->maxLength(255)
                    ->placeholder(__('https://waze.com/ul/...')),
            ] : []),
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
        bool $includeWazeUrlField = true,
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
            includeWazeUrlField: $includeWazeUrlField,
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeAddressFormState(array $data): array
    {
        return AddressFormState::normalizeAddressFormState($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareAddressPersistenceData(array $data): array
    {
        return AddressFormState::prepareAddressPersistenceData($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function countrySelectionProvided(array $data): bool
    {
        return AddressFormState::countrySelectionProvided($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function normalizeCountrySelection(array $data): ?string
    {
        return AddressFormState::normalizeCountrySelection($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function hydrateAddressFormState(array $data): array
    {
        $data = self::expandStoredAreasForForm($data);

        $googleMapsUrl = is_string($data['google_maps_url'] ?? null) ? trim($data['google_maps_url']) : null;
        $googlePlaceId = is_string($data['google_place_id'] ?? null)
            ? trim((string) $data['google_place_id'])
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
                'google_place_id',
                'google_feature_id',
                'google_cid',
                'google_entity_id',
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
            'google_place_id' => $get('google_place_id'),
            'google_feature_id' => $get('google_feature_id'),
            'google_cid' => $get('google_cid'),
            'google_entity_id' => $get('google_entity_id'),
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
            'google_place_id',
            'google_feature_id',
            'google_cid',
            'google_entity_id',
            'google_display_name',
            'latitude',
            'longitude',
            'google_resolution_source',
            'google_resolution_status',
            'google_resolution_fingerprint',
            'google_resolution_message',
        ] as $field) {
            $set($field, match ($field) {
                'google_place_id' => $normalized['google_place_id'] ?? null,
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
     * State options from package `states` table (addresses.state_id).
     *
     * @return array<int|string, string>
     */
    public static function stateOptionsForCountry(int|string|null $countryId): array
    {
        self::ensureCacheScope();
        $countryId = AddressFormState::normalizeLocationId($countryId);

        if ($countryId === null) {
            return [];
        }

        return self::$stateOptions[$countryId] ??= app(SelectionCatalog::class)
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
        $stateId = AddressFormState::normalizeLocationId($stateId);
        $countryId = AddressFormState::normalizeLocationId($countryId);

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

        return self::$cityOptions[$cacheKey] = app(SelectionCatalog::class)
            ->rememberAddressOptions("cities:{$cacheKey}", static fn (): array => $query
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all());
    }

    /** @return array<int|string, string> */
    public static function districtOptionsForState(int|string|null $stateId, int|string|null $countryId = null): array
    {
        $stateId = AddressFormState::normalizeLocationId($stateId);
        $countryId = AddressFormState::normalizeLocationId($countryId);

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
        $districtId = AddressFormState::normalizeLocationId($districtId);
        $stateId = AddressFormState::normalizeLocationId($stateId);
        $countryId = AddressFormState::normalizeLocationId($countryId) ?? self::countryIdForState($stateId);

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
        $countryId = AddressFormState::normalizeLocationId($countryId);

        if ($countryId === null && $parentId !== null) {
            $countryId = self::resolveCountryIdForParent($parentId);
        }

        if ($countryId === null) {
            return [];
        }

        $parentId = AddressFormState::normalizeLocationId($parentId);
        $cacheKey = "{$countryId}:{$role}:".($parentId ?? 'root');

        if (array_key_exists($cacheKey, self::$areaOptions)) {
            return self::$areaOptions[$cacheKey];
        }

        $query = self::areaQueryForRole($countryId, $role, $parentId);

        if (! $query instanceof Builder) {
            return [];
        }

        return self::$areaOptions[$cacheKey] = app(SelectionCatalog::class)
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
        $countryId = AddressFormState::normalizeLocationId($countryId);

        if ($countryId === null && $parentId !== null) {
            $countryId = self::resolveCountryIdForParent($parentId);
        }

        if ($countryId === null) {
            return [];
        }

        $parentId = AddressFormState::normalizeLocationId($parentId);
        $cacheKey = "{$countryId}:{$role}:".($parentId ?? 'root');

        if (array_key_exists($cacheKey, self::$areaSlugs)) {
            return self::$areaSlugs[$cacheKey];
        }

        $query = self::areaQueryForRole($countryId, $role, $parentId);

        if (! $query instanceof Builder) {
            return [];
        }

        return self::$areaSlugs[$cacheKey] = app(SelectionCatalog::class)
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

        $countryId = app(SelectionCatalog::class)->rememberAddressValue(
            "country-for-state:{$stateId}",
            static fn (): ?string => State::query()->whereKey($stateId)->value('country_id'),
        );

        return self::$countryIdsByState[$stateId] = is_string($countryId) ? $countryId : null;
    }

    private static function resolveCountryIdForParent(int|string $parentId): ?string
    {
        $parentKey = AddressFormState::normalizeLocationId($parentId);

        if ($parentKey === null) {
            return null;
        }

        self::ensureCacheScope();

        if (array_key_exists($parentKey, self::$countryIdsByParent)) {
            return self::$countryIdsByParent[$parentKey];
        }

        $countryId = app(SelectionCatalog::class)->rememberAddressValue(
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
            $areaId = AddressFormState::normalizeLocationId($areaId);

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
        $stateId = AddressFormState::normalizeLocationId($data['state_id'] ?? null)
            ?? self::stateIdFromStoredAreas($assignments);
        $cityId = AddressFormState::normalizeLocationId($data['city_id'] ?? null);

        $countryId = AddressFormState::normalizeLocationId($data['country_id'] ?? null);

        if ($stateId === null && is_string($data['state'] ?? null) && trim($data['state']) !== '') {
            $stateQuery = State::query()->whereLike('name', trim($data['state']));

            if ($countryId !== null) {
                $stateQuery->where('country_id', $countryId);
            }

            $stateId = AddressFormState::normalizeLocationId($stateQuery->value('id'));
        }

        if ($cityId === null && is_string($data['city'] ?? null) && trim($data['city']) !== '') {
            $cityQuery = City::query()->whereLike('name', trim($data['city']));

            if ($stateId !== null) {
                $cityQuery->where('state_id', $stateId);
            }

            if ($countryId !== null) {
                $cityQuery->where('country_id', $countryId);
            }

            $cityId = AddressFormState::normalizeLocationId($cityQuery->value('id'));
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
        return AddressAreaRoles::cascadeResetGuard();
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

        return self::$countryOptions['all'] ??= app(SelectionCatalog::class)
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
                ->disabled(fn (Get $get): bool => $includeCountryField && AddressFormState::normalizeLocationId($get('country_id')) === null)
                ->visible(fn (Get $get): bool => self::stateSelectOptions(
                    $includeCountryField ? $get('country_id') : $defaultCountryId,
                ) !== [])
                ->afterStateUpdated(function (Set $set): void {
                    $set('city_id', null);
                    $set('google_inferred_area_roles', []);

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
                ->helperText(function (Get $get) use ($role): ?string {
                    $inferredRoles = $get('google_inferred_area_roles');

                    return is_array($inferredRoles) && in_array($role, $inferredRoles, true)
                        ? __('Suggested from the selected place name. Review this area.')
                        : null;
                })
                ->searchable()
                ->live()
                ->visible(fn (Get $get): bool => self::areaVisible(
                    $get,
                    $includeCountryField ? $get('country_id') : $defaultCountryId,
                    $role,
                ))
                ->afterStateUpdated(function (Set $set, Get $get) use ($role, $includeCountryField, $defaultCountryId): void {
                    $childRoles = self::childAreaRoles($includeCountryField ? $get('country_id') : $defaultCountryId, $role);

                    foreach ($childRoles as $child) {
                        $set("area_assignments.{$child}", null);
                    }

                    $inferredRoles = $get('google_inferred_area_roles');
                    $inferredRoles = is_array($inferredRoles) ? $inferredRoles : [];

                    $set('google_inferred_area_roles', array_values(array_diff($inferredRoles, [$role, ...$childRoles])));
                })
                ->native(false);
        }

        return array_merge($fields, [
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
        return AddressAreaRoles::entryRoles();
    }

    /**
     * @return array<int|string, string>
     */
    private static function stateSelectOptions(mixed $countryId): array
    {
        return app(LocationSlugResolver::class)->stateOptionsForCountry(AddressFormState::normalizeLocationId($countryId));
    }

    /**
     * Roles whose level sits directly below the given role in the country.
     *
     * @return list<string>
     */
    private static function childAreaRoles(mixed $countryId, string $role): array
    {
        $countryId = AddressFormState::normalizeLocationId($countryId);

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

            $countryId = AddressFormState::normalizeLocationId($includeCountryField ? $get('country_id') : $defaultCountryId)
                ?? self::countryIdForState(AddressFormState::normalizeLocationId($stateId));

            if (self::isFederalTerritory(AddressFormState::normalizeLocationId($stateId), $countryId)) {
                return false;
            }

            // City is redundant when the country's first cascade slot applies.
            $slot0 = $countryId === null ? null : app(LocationSlugResolver::class)->districtRoleForCountry($countryId);

            if ($slot0 !== null && self::areaOptionsForRole($countryId, $slot0, AddressFormState::normalizeLocationId($stateId)) !== []) {
                return false;
            }

            $hasCityOptions = self::cityOptionsForState($stateId, $countryId) !== [];

            return $isSelect ? $hasCityOptions : ! $hasCityOptions;
        };
    }

    private static function areaVisible(Get $get, mixed $countryId, string $role): bool
    {
        $countryId = AddressFormState::normalizeLocationId($countryId) ?? self::countryIdForState(AddressFormState::normalizeLocationId($get('state_id')));
        $stateId = AddressFormState::normalizeLocationId($get('state_id'));
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
        $countryId = AddressFormState::normalizeLocationId($countryId);

        if ($countryId === null) {
            return null;
        }

        $areaIds = self::formAreaIds($get, $countryId);

        return app(CountryAddressProfileResolver::class)->parentAreaIdForRole(
            $countryId,
            $role,
            AddressFormState::normalizeLocationId($get('state_id')),
            $areaIds,
            static fn (string $probeRole, string $probeParentId): bool => self::areaOptionsForRole($countryId, $probeRole, $probeParentId) !== [],
        );
    }

    /**
     * @return array<string, ?string>
     */
    private static function formAreaIds(Get $get, mixed $countryId): array
    {
        $countryId = AddressFormState::normalizeLocationId($countryId);

        if ($countryId === null) {
            return [];
        }

        $areaIds = [];

        foreach (app(CountryAddressProfileResolver::class)->assignmentRoles($countryId) as $areaRole) {
            $areaIds[$areaRole] = AddressFormState::normalizeLocationId($get("area_assignments.{$areaRole}"));
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
        return LocationLevelLabels::label(
            AddressFormState::normalizeLocationId($countryId),
            $role,
            $fallback,
            AddressFormState::normalizeLocationId($stateId),
            $areaIds,
        );
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
                $set('google_inferred_area_roles', [])
            }
            JS;
    }

    private static function cityCascadeResetScript(): string
    {
        return <<<'JS'
            // City is independent of district/subdistrict; no child reset.
            JS;
    }
}
