<?php

namespace App\Features\Contributions\EntryPoints\Filament\Forms;

use AIArmada\Addressing\GooglePlaces\GooglePlacesConfiguration;
use AIArmada\FilamentContacting\Schemas\ContactMethodRepeaterSchema;
use AIArmada\FilamentContacting\Schemas\SocialProfileRepeaterSchema;
use App\Features\DonationChannels\Api\DonationChannelCountryProfiles;
use App\Features\Institutions\Api\Enums\InstitutionNameType;
use App\Features\Institutions\Api\Enums\InstitutionType;
use App\Platform\Ui\Forms\AddressFields;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;

class InstitutionContributionFormSchema
{
    /**
     * @return array<int, Component>
     */
    public static function components(
        bool $includeMedia = true,
        bool $requireGoogleMaps = false,
        ?string $addressStatePath = null,
        bool $includeLocationPicker = false,
        bool $mapsSelectionRequired = false,
        bool $includeWazeUrlField = true,
        bool $includeMapsReselection = false,
        bool $hideGoogleMapsUrlField = false,
        bool $mapsReselectionRequired = false,
    ): array {
        $shouldRenderLocationPicker = self::shouldRenderLocationPicker(
            $includeLocationPicker,
            $addressStatePath,
            $mapsSelectionRequired,
        );
        $shouldRenderReselection = self::shouldRenderMapsReselection(
            $addressStatePath,
            $includeMapsReselection,
            $mapsSelectionRequired,
        );
        $mapsSearchSection = $mapsSelectionRequired
            ? [
                Section::make(__('Find the institution on Google Maps'))
                    ->description(__('Search and select the institution before entering its details.'))
                    ->schema([
                        View::make('filament.schemas.components.institution-location-picker')
                            ->statePath($addressStatePath)
                            ->columnSpanFull()
                            ->viewData([
                                'mapsApiKey' => GooglePlacesConfiguration::apiKey(),
                                'applyMethod' => 'applyPlaceSelection',
                                'requiredSelection' => true,
                                'failureMessage' => __('Google Maps search is unavailable. Retry the search before submitting.'),
                            ]),
                    ]),
            ]
            : [];
        $mapsReselectSection = $shouldRenderReselection
            ? [
                Section::make(__('Update the location on Google Maps'))
                    ->description(__('Only search again if the institution moved or the pinned location is wrong. Leave this untouched to keep the current address.'))
                    ->schema([
                        View::make('filament.schemas.components.institution-location-picker')
                            ->statePath($addressStatePath)
                            ->columnSpanFull()
                            ->viewData([
                                'mapsApiKey' => GooglePlacesConfiguration::apiKey(),
                                'applyMethod' => 'applyPlaceSelection',
                                'requiredSelection' => $mapsReselectionRequired,
                                'showErrors' => true,
                                'showPhotos' => true,
                            ]),
                    ]),
            ]
            : [];

        $components = [
            ...$mapsSearchSection,
            ...$mapsReselectSection,
            Section::make(__('Institution Profile'))
                ->schema([
                    Select::make('type')
                        ->label(__('Institution Type'))
                        ->helperText(__('Google Maps suggests an institution type when it can; review or change it here.'))
                        ->options(InstitutionType::class)
                        ->required(),
                    TextInput::make('name')
                        ->label(__('Institution Name'))
                        ->required()
                        ->maxLength(255)
                        ->helperText(__('Use the official name of this institution.')),
                    Repeater::make('names')
                        ->label(__('Alternative Names'))
                        ->helperText(__('Add other names, abbreviations, or local names used by this institution.'))
                        ->schema([
                            Select::make('name_type')
                                ->label(__('Name type'))
                                ->placeholder(__('Select name type'))
                                ->options(fn (): array => self::institutionNameTypeOptions())
                                ->required(),
                            TextInput::make('full_name')
                                ->label(__('Full name'))
                                ->required()
                                ->maxLength(255),
                            TextInput::make('language_code')
                                ->label(__('Name language'))
                                ->maxLength(10)
                                ->default('ms'),
                            Toggle::make('is_primary')
                                ->label(__('Primary name'))
                                ->helperText(__('Mark this if this is the main displayed name for the institution.'))
                                ->default(false)
                                ->fixIndistinctState(),
                        ])
                        ->columns(2)
                        ->columnSpanFull()
                        ->defaultItems(0)
                        ->addActionLabel(__('Add name')),
                    RichEditor::make('description')
                        ->label(__('Description'))
                        ->helperText(__('Share a short description of this institution.'))
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make(__('Address'))
                ->description(__('Choose the country and region where this institution is based.'))
                ->schema([
                    ...($shouldRenderLocationPicker && ! $mapsSelectionRequired && ! $shouldRenderReselection
                        ? [
                            View::make('filament.schemas.components.institution-location-picker')
                                ->statePath($addressStatePath)
                                ->columnSpanFull()
                                ->viewData([
                                    'mapsApiKey' => GooglePlacesConfiguration::apiKey(),
                                ]),
                        ]
                        : []),
                    ...($addressStatePath === null
                        ? AddressFields::addressFields(
                            requireGoogleMaps: $requireGoogleMaps && ! $mapsSelectionRequired,
                            showGoogleMapsUrlField: ! $mapsSelectionRequired && ! $hideGoogleMapsUrlField,
                            enableGoogleMapsNormalization: true,
                            enableGoogleMapsRemoteLookup: $shouldRenderLocationPicker && ! $mapsSelectionRequired && ! $shouldRenderReselection,
                            includeCountryField: true,
                            showCountryField: false,
                            requireCountryField: true,
                            includeWazeUrlField: $includeWazeUrlField,
                        )
                        : [AddressFields::addressGroup(
                            requireGoogleMaps: $requireGoogleMaps && ! $mapsSelectionRequired,
                            statePath: $addressStatePath,
                            showGoogleMapsUrlField: ! $mapsSelectionRequired && ! $hideGoogleMapsUrlField,
                            enableGoogleMapsNormalization: true,
                            enableGoogleMapsRemoteLookup: $shouldRenderLocationPicker && ! $mapsSelectionRequired && ! $shouldRenderReselection,
                            includeCountryField: true,
                            showCountryField: false,
                            requireCountryField: true,
                            includeWazeUrlField: $includeWazeUrlField,
                        )]),
                ])
                ->columns($addressStatePath === null ? 2 : 1),
            Section::make(__('Contact'))
                ->schema([
                    ContactMethodRepeaterSchema::make(),
                ]),
            Section::make(__('Social Media'))
                ->schema([
                    SocialProfileRepeaterSchema::make(helperText: __('Add social media links for this institution')),
                ]),
            Section::make(__('Donation Channels'))
                ->description(__('Share official donation accounts for this institution.'))
                ->visible(fn (Get $get): bool => self::donationCountryId($get) === null || self::donationMethodOptions($get) !== [])
                ->schema([
                    Repeater::make('donation_channels')
                        ->label(__('Donation Channels'))
                        ->schema([
                            TextInput::make('label')
                                ->label(__('Donation channel label'))
                                ->placeholder(__('e.g. Tabung Masjid, Dana Pembangunan'))
                                ->maxLength(255),
                            TextInput::make('recipient')
                                ->label(__('Recipient'))
                                ->placeholder(__('Full name on account'))
                                ->required()
                                ->maxLength(255),
                            Select::make('method')
                                ->label(__('Method'))
                                ->options(fn (Get $get): array => self::donationMethodOptions($get))
                                ->helperText(__('Available methods follow the institution country bank catalog. DuitNow and e-wallet channels are available for Malaysian institutions only.'))
                                ->required()
                                ->live(),
                            Select::make('currency')
                                ->label(__('Currency'))
                                ->options(fn (): array => self::countryProfiles()->currencyOptions())
                                ->default(fn (Get $get): ?string => self::countryProfiles()
                                    ->forCountryId(self::donationCountryId($get))
                                    ->default_currency)
                                ->searchable()
                                ->visible(fn (Get $get): bool => $get('method') === 'bank_account')
                                ->helperText(__('Defaults to the institution country currency. Change it if the account uses another currency.'))
                                ->native(false),
                            Select::make('bank_code')
                                ->label(__('Bank'))
                                ->options(fn (Get $get): array => self::donationBankOptions($get))
                                ->searchable()
                                ->native(false)
                                ->visible(fn (Get $get): bool => $get('method') === 'bank_account')
                                ->required(fn (Get $get): bool => $get('method') === 'bank_account')
                                ->helperText(__('Select a bank from the institution country bank catalog. The stored bank name is derived from it.')),
                            TextInput::make('account_number')
                                ->label(__('Account Number'))
                                ->visible(fn (Get $get): bool => $get('method') === 'bank_account')
                                ->required(fn (Get $get): bool => $get('method') === 'bank_account'
                                    && blank($get('iban'))),
                            TextInput::make('iban')
                                ->label('IBAN')
                                ->maxLength(34)
                                ->visible(fn (Get $get): bool => $get('method') === 'bank_account')
                                ->required(fn (Get $get): bool => $get('method') === 'bank_account'
                                    && blank($get('account_number')))
                                ->helperText(__('Use an IBAN when the account does not use a local account number.')),
                            TextInput::make('swift_bic')
                                ->label(__('SWIFT / BIC'))
                                ->maxLength(11)
                                ->visible(fn (Get $get): bool => $get('method') === 'bank_account')
                                ->helperText(__('8 or 11 characters. Needed for international transfers.')),
                            TextInput::make('routing_code')
                                ->label(__('Routing / sort code'))
                                ->maxLength(20)
                                ->visible(fn (Get $get): bool => $get('method') === 'bank_account'
                                    && self::donationRoutingSupported($get))
                                ->helperText(__('Local clearing or branch code used by some international banks, for example an Indonesian bank code or a Singapore branch code.')),
                            TextInput::make('duitnow_type')
                                ->label(__('DuitNow Type'))
                                ->maxLength(255)
                                ->visible(fn (Get $get): bool => $get('method') === 'duitnow')
                                ->required(fn (Get $get): bool => $get('method') === 'duitnow'),
                            TextInput::make('duitnow_value')
                                ->label(__('DuitNow Value'))
                                ->visible(fn (Get $get): bool => $get('method') === 'duitnow')
                                ->required(fn (Get $get): bool => $get('method') === 'duitnow'),
                            TextInput::make('ewallet_provider')
                                ->label(__('E-Wallet Provider'))
                                ->maxLength(255)
                                ->visible(fn (Get $get): bool => $get('method') === 'ewallet')
                                ->required(fn (Get $get): bool => $get('method') === 'ewallet'),
                            TextInput::make('ewallet_handle')
                                ->label(__('E-Wallet Handle'))
                                ->maxLength(255)
                                ->visible(fn (Get $get): bool => $get('method') === 'ewallet')
                                ->required(fn (Get $get): bool => $get('method') === 'ewallet' && blank($get('ewallet_qr_payload'))),
                            Textarea::make('ewallet_qr_payload')
                                ->label(__('E-Wallet QR Payload'))
                                ->visible(fn (Get $get): bool => $get('method') === 'ewallet')
                                ->required(fn (Get $get): bool => $get('method') === 'ewallet' && blank($get('ewallet_handle')))
                                ->columnSpanFull(),
                            Textarea::make('reference_note')
                                ->label(__('Reference Note'))
                                ->maxLength(255)
                                ->columnSpanFull(),
                            FileUpload::make('qr_upload')
                                ->label(__('QR Image'))
                                ->helperText(__('Upload an official payment QR image.'))
                                ->image()
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                ->maxSize(10240)
                                ->storeFiles(false)
                                ->columnSpanFull(),
                            Toggle::make('is_default')
                                ->label(__('Default'))
                                ->helperText(__('Mark this as the primary donation channel.'))
                                ->default(false)
                                ->fixIndistinctState(),
                        ])
                        ->columns(2)
                        ->columnSpanFull()
                        ->defaultItems(0)
                        ->addActionLabel(__('Add donation channel')),
                ]),
        ];

        if ($includeMedia) {
            array_splice($components, ($mapsSelectionRequired || $shouldRenderReselection) ? 3 : 2, 0, [
                self::mediaSection(),
            ]);
        }

        return $components;
    }

    /**
     * @param  list<string>  $mediaFields
     * @return array<int, Component>
     */
    public static function directEditComponents(
        ?string $addressStatePath = null,
        bool $includeLocationPicker = false,
        array $mediaFields = [],
        bool $includeWazeUrlField = true,
        bool $includeMapsReselection = false,
        bool $hideGoogleMapsUrlField = true,
        bool $mapsReselectionRequired = false,
        ?Closure $configureMedia = null,
    ): array {
        $components = self::components(
            includeMedia: false,
            addressStatePath: $addressStatePath,
            includeLocationPicker: $includeLocationPicker,
            includeWazeUrlField: $includeWazeUrlField,
            includeMapsReselection: $includeMapsReselection,
            hideGoogleMapsUrlField: $hideGoogleMapsUrlField,
            mapsReselectionRequired: $mapsReselectionRequired,
        );

        if ($mediaFields !== []) {
            $reselectRendered = self::shouldRenderMapsReselection(
                $addressStatePath,
                $includeMapsReselection,
                false,
            );

            array_splice($components, $reselectRendered ? 3 : 2, 0, [self::mediaSection($mediaFields, $configureMedia)]);
        }

        return $components;
    }

    /**
     * @param  list<string>|null  $mediaFields
     */
    private static function mediaSection(?array $mediaFields = null, ?Closure $configureMedia = null): Section
    {
        $fields = [
            SpatieMediaLibraryFileUpload::make('logo')
                ->label(__('Logo'))
                ->collection('logo')
                ->image()
                ->imageEditor()
                ->conversion('thumb')
                ->helperText(__('Institution logo'))
                ->columnSpanFull(),
            SpatieMediaLibraryFileUpload::make('cover')
                ->label(__('Cover Image'))
                ->collection('cover')
                ->image()
                ->imageEditor()
                ->imageAspectRatio('16:9')
                ->automaticallyOpenImageEditorForAspectRatio()
                ->imageEditorAspectRatioOptions(['16:9'])
                ->rules(['dimensions:ratio=16/9'])
                ->automaticallyCropImagesToAspectRatio()
                ->conversion('banner')
                ->responsiveImages()
                ->helperText(__('Header or banner image'))
                ->columnSpanFull(),
            SpatieMediaLibraryFileUpload::make('gallery')
                ->label(__('Gallery'))
                ->collection('gallery')
                ->multiple()
                ->reorderable()
                ->maxFiles(10)
                ->image()
                ->conversion('gallery_thumb')
                ->responsiveImages()
                ->helperText(__('Up to 10 photos of the institution'))
                ->columnSpanFull(),
        ];

        if ($mediaFields !== null) {
            $fields = array_filter($fields, fn (SpatieMediaLibraryFileUpload $field): bool => in_array($field->getName(), $mediaFields, true));

            foreach ($fields as $field) {
                if (! $field->isMultiple()) {
                    $field->deletable(false);
                }
            }
        }

        if ($configureMedia instanceof Closure) {
            foreach ($fields as $field) {
                $configureMedia($field);
            }
        }

        return Section::make(__('Media'))
            ->schema(array_values($fields))
            ->columns(['default' => 1, 'sm' => 2]);
    }

    private static function shouldRenderLocationPicker(
        bool $includeLocationPicker,
        ?string $addressStatePath,
        bool $mapsSelectionRequired,
    ): bool {
        return $includeLocationPicker
            && $addressStatePath !== null
            && ($mapsSelectionRequired || GooglePlacesConfiguration::isEnabled());
    }

    private static function shouldRenderMapsReselection(
        ?string $addressStatePath,
        bool $includeMapsReselection,
        bool $mapsSelectionRequired,
    ): bool {
        return $includeMapsReselection
            && $addressStatePath !== null
            && ! $mapsSelectionRequired
            && GooglePlacesConfiguration::isEnabled();
    }

    /**
     * @return array<string, string>
     */
    private static function institutionNameTypeOptions(): array
    {
        return collect(InstitutionNameType::cases())
            ->mapWithKeys(fn (InstitutionNameType $type): array => [$type->value => __($type->label())])
            ->all();
    }

    private static function countryProfiles(): DonationChannelCountryProfiles
    {
        return app(DonationChannelCountryProfiles::class);
    }

    private static function donationCountryId(Get $get): ?string
    {
        $countryId = $get('../../address.country_id');

        return is_string($countryId) && $countryId !== '' ? $countryId : null;
    }

    /**
     * @return array<string, string>
     */
    private static function donationMethodOptions(Get $get): array
    {
        $labels = [
            'bank_account' => __('Bank Account'),
            'duitnow' => 'DuitNow',
            'ewallet' => __('E-Wallet'),
        ];

        return array_intersect_key(
            $labels,
            array_flip(self::countryProfiles()->forCountryId(self::donationCountryId($get))->methods),
        );
    }

    /**
     * @return array<array-key, string>
     */
    private static function donationBankOptions(Get $get): array
    {
        return self::countryProfiles()->forCountryId(self::donationCountryId($get))->banks->options();
    }

    private static function donationRoutingSupported(Get $get): bool
    {
        return self::countryProfiles()->forCountryId(self::donationCountryId($get))->supports_routing_codes;
    }
}
