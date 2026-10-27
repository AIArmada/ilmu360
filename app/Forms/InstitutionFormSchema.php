<?php

namespace App\Forms;

use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Membership\Actions\AddMemberAction;
use AIArmada\Membership\Enums\MemberRole;
use App\Actions\Contributions\Submission\EnsureUniqueContributionCreateAction;
use App\Actions\Institutions\GenerateInstitutionSlugAction;
use App\Actions\Institutions\WithInstitutionMapsIdentityLockAction;
use App\Enums\ContributionSubjectType;
use App\Enums\InstitutionNameType;
use App\Enums\InstitutionStatus;
use App\Enums\InstitutionType;
use App\Models\Institution;
use App\Models\User;
use App\Support\Cache\SelectionCatalogCache;
use App\Support\Language\MalaysiaLanguageCatalog;
use App\Support\Location\GooglePlacesConfiguration;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InstitutionFormSchema
{
    /**
     * Shared createOptionForm for Institution selects.
     *
     * @return array<int, Component>
     */
    public static function createOptionForm(
        bool $includeLocationPicker = false,
        bool $requireVerifiedMapsSelection = false,
        ?string $mapsSelectionOwnerPath = null,
    ): array {
        return [
            ...($requireVerifiedMapsSelection
                ? [
                    Section::make(__('Find the institution on Google Maps'))
                        ->description(__('Search and select the institution before entering its details.'))
                        ->schema([
                            self::mapsPickerView(
                                $mapsSelectionOwnerPath,
                                requiredSelection: true,
                                photoPreviewsMethod: 'loadQuickAddInstitutionPhotoPreviews',
                            )
                                ->statePath('address')
                                ->columnSpanFull(),
                        ]),
                ]
                : []),
            TextInput::make('name')
                ->label(__('Institution Name'))
                ->required()
                ->maxLength(255)
                ->placeholder(__('e.g., Masjid Al-Falah, Surau An-Nur')),

            Repeater::make('names')
                ->label(__('Alternative Names'))
                ->schema([
                    Select::make('name_type')
                        ->label(__('Name type'))
                        ->placeholder(__('Select name type'))
                        ->options(fn (): array => collect(InstitutionNameType::cases())
                            ->mapWithKeys(fn (InstitutionNameType $type): array => [$type->value => __($type->label())])
                            ->all())
                        ->required(),
                    TextInput::make('full_name')
                        ->label(__('Full name'))
                        ->required()
                        ->maxLength(255),
                    Select::make('language_code')
                        ->label(__('Name language'))
                        ->placeholder(__('Select language'))
                        ->options(fn (): array => app(SelectionCatalogCache::class)->languageOptions(
                            'code',
                            MalaysiaLanguageCatalog::labels(),
                        ))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->default('ms'),
                    Toggle::make('is_primary')
                        ->label(__('Primary name'))
                        ->default(false)
                        ->fixIndistinctState(),
                ])
                ->columns(2)
                ->defaultItems(0)
                ->addActionLabel(__('Add name')),

            Select::make('type')
                ->label(__('Institution Type'))
                ->required()
                ->options(InstitutionType::class)
                ->placeholder(__('Select type...')),

            SpatieMediaLibraryFileUpload::make('logo')
                ->label(__('Logo'))
                ->collection('logo')
                ->image()
                ->imageEditor()
                ->conversion('thumb')
                ->helperText(__('Institution logo')),

            RichEditor::make('description')
                ->label(__('Description')),

            SpatieMediaLibraryFileUpload::make('cover')
                ->label(__('Cover Image'))
                ->collection('cover')
                ->image()
                ->imageEditor()
                ->imageAspectRatio('16:9')
                ->automaticallyOpenImageEditorForAspectRatio()
                ->imageEditorAspectRatioOptions(['16:9'])
                ->automaticallyCropImagesToAspectRatio()
                ->conversion('banner')
                ->responsiveImages()
                ->helperText(__('Header or banner image')),

            SpatieMediaLibraryFileUpload::make('gallery')
                ->label(__('Gallery'))
                ->collection('gallery')
                ->multiple()
                ->image()
                ->imageEditor()
                ->conversion('gallery_thumb')
                ->responsiveImages()
                ->maxFiles(10)
                ->helperText(__('Up to 10 photos of the institution')),

            SharedFormSchema::contactsRepeater(__('Add contact details for this institution')),

            ...self::addressSchema(
                includeLocationPicker: $includeLocationPicker,
                requireVerifiedMapsSelection: $requireVerifiedMapsSelection,
            ),

            SharedFormSchema::socialMediaRepeater('Add social media links for this institution'),
        ];
    }

    /**
     * Shared createOptionUsing callback for Institution selects.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $verifiedMapsSelection
     */
    public static function createOptionUsing(
        array $data,
        ?Schema $schema = null,
        bool $requireVerifiedMapsSelection = false,
        ?array $verifiedMapsSelection = null,
    ): string {
        $addressData = is_array($data['address'] ?? null) ? $data['address'] : $data;

        if ($requireVerifiedMapsSelection) {
            $verifiedPlaceId = $verifiedMapsSelection['place_id'] ?? null;
            $submittedPlaceId = $addressData['google_place_id'] ?? null;

            if (! is_string($verifiedPlaceId)
                || ! is_string($submittedPlaceId)
                || ! hash_equals($verifiedPlaceId, $submittedPlaceId)
                || ! is_array($verifiedMapsSelection['address'] ?? null)) {
                throw ValidationException::withMessages([
                    (($schema?->getStatePath() !== null ? rtrim($schema->getStatePath(), '.').'.' : '')
                        .'address.google_place_id') => __('Select and verify the institution on Google Maps before saving.'),
                ]);
            }

            $verifiedAddress = $verifiedMapsSelection['address'];
            $addressData = array_merge($addressData, Arr::only($verifiedAddress, [
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
                'formatted_address',
                'components',
                'metadata',
                'navigation_links',
            ]));
            $data['address'] = $addressData;
        }

        $create = fn (): string => DB::transaction(function () use ($data, $schema, $addressData, $requireVerifiedMapsSelection): string {
            if ($requireVerifiedMapsSelection) {
                app(EnsureUniqueContributionCreateAction::class)->handle(
                    ContributionSubjectType::Institution,
                    [
                        'name' => $data['name'] ?? null,
                        'address' => $addressData,
                    ],
                    (string) ($schema?->getStatePath() ?? ''),
                );
            }

            $institution = Institution::create([
                'name' => $data['name'],
                'slug' => app(GenerateInstitutionSlugAction::class)->handle((string) $data['name'], $addressData),
                'type' => $data['type'],
                'description' => $data['description'] ?? null,
                'status' => InstitutionStatus::Pending,
            ]);

            $names = is_array($data['names'] ?? null) ? $data['names'] : [];

            $primarySelected = false;

            foreach ($names as $name) {
                $isPrimary = (bool) ($name['is_primary'] ?? true) && ! $primarySelected;

                if ($isPrimary) {
                    $primarySelected = true;
                }

                $institution->names()->create([
                    'name_type' => $name['name_type'] ?? InstitutionNameType::Nickname,
                    'full_name' => trim((string) ($name['full_name'] ?? '')),
                    'language_code' => $name['language_code'] ?? 'ms',
                    'is_primary' => $isPrimary,
                ]);
            }

            $creator = auth()->user();

            if ($creator instanceof User) {
                AddMemberAction::run($institution, $creator, MemberRole::Owner);
            }

            // Save media uploads (cover, gallery) via Filament's relationship-saving mechanism
            $schema?->model($institution)->saveRelationships();

            SharedFormSchema::createContactsFromData($institution, $data);
            SharedFormSchema::createAddressFromData($institution, $addressData, allowCountryOnly: true);
            SharedFormSchema::createSocialMediaFromData($institution, $data);
            app(GenerateInstitutionSlugAction::class)->syncInstitutionSlug($institution);

            return (string) $institution->getKey();
        });

        if (! $requireVerifiedMapsSelection) {
            return $create();
        }

        return app(WithInstitutionMapsIdentityLockAction::class)->handle($data, $create);
    }

    /**
     * @return array<int, Component>
     */
    private static function addressSchema(bool $includeLocationPicker, bool $requireVerifiedMapsSelection = false): array
    {
        $defaultCountryId = self::malaysiaCountryId();

        if (! $includeLocationPicker) {
            return SharedFormSchema::addressFields(
                requireGoogleMaps: true,
                includeCountryField: true,
                showCountryField: true,
                defaultCountryId: $defaultCountryId,
                requireCountryField: true,
            );
        }

        $shouldRenderLocationPicker = GooglePlacesConfiguration::isEnabled();

        return [
            Group::make([
                ...($shouldRenderLocationPicker && ! $requireVerifiedMapsSelection
                    ? [
                        self::mapsPickerView(),
                    ]
                    : []),
                ...($requireVerifiedMapsSelection
                    ? [
                        Hidden::make('maps_selection_nonce'),
                        Hidden::make('maps_selection_token'),
                    ]
                    : []),
                ...SharedFormSchema::addressFields(
                    requireGoogleMaps: ! $requireVerifiedMapsSelection,
                    showGoogleMapsUrlField: ! $shouldRenderLocationPicker && ! $requireVerifiedMapsSelection,
                    enableGoogleMapsNormalization: ! $requireVerifiedMapsSelection,
                    enableGoogleMapsRemoteLookup: $shouldRenderLocationPicker && ! $requireVerifiedMapsSelection,
                    includeCountryField: true,
                    showCountryField: true,
                    defaultCountryId: $defaultCountryId,
                    requireCountryField: true,
                ),
            ])
                ->statePath('address')
                ->columns(2),
        ];
    }

    private static function malaysiaCountryId(): ?string
    {
        $countryId = AddressCountry::query()
            ->where('iso2', 'MY')
            ->value('id');

        return $countryId === null ? null : (string) $countryId;
    }

    private static function mapsPickerView(
        ?string $ownerStatePath = null,
        bool $requiredSelection = false,
        string $photoPreviewsMethod = 'loadPlacePhotoPreviews',
    ): View {
        return View::make('filament.schemas.components.institution-location-picker')
            ->columnSpanFull()
            ->viewData([
                'mapsApiKey' => GooglePlacesConfiguration::apiKey(),
                'title' => $requiredSelection ? null : __('Find the institution location'),
                'description' => $requiredSelection
                    ? null
                    : __('Search like a ride-hailing destination, pick the correct place, then confirm it on the map before saving.'),
                'searchLabel' => __('Search for an institution or address'),
                'applyMethod' => $ownerStatePath !== null ? 'applyQuickAddInstitutionPlaceSelection' : 'applyLocationPickerSelection',
                'photoPreviewsMethod' => $photoPreviewsMethod,
                'requiredSelection' => $requiredSelection,
                'ownerStatePath' => $ownerStatePath,
                'failureMessage' => __('Google Maps search is unavailable. Retry the search before submitting.'),
            ]);
    }
}
