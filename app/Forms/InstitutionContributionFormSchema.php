<?php

namespace App\Forms;

use App\Enums\InstitutionNameType;
use App\Enums\InstitutionType;
use App\Support\Location\GooglePlacesConfiguration;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
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
    ): array {
        $shouldRenderLocationPicker = self::shouldRenderLocationPicker(
            $includeLocationPicker,
            $addressStatePath,
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

        $components = [
            ...$mapsSearchSection,
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
                    ...($shouldRenderLocationPicker && ! $mapsSelectionRequired
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
                        ? SharedFormSchema::addressFields(
                            requireGoogleMaps: $requireGoogleMaps && ! $mapsSelectionRequired,
                            showGoogleMapsUrlField: ! $mapsSelectionRequired,
                            enableGoogleMapsNormalization: true,
                            enableGoogleMapsRemoteLookup: $shouldRenderLocationPicker && ! $mapsSelectionRequired,
                            includeCountryField: true,
                            showCountryField: false,
                            requireCountryField: true,
                            includeWazeUrlField: $includeWazeUrlField,
                        )
                        : [SharedFormSchema::addressGroup(
                            requireGoogleMaps: $requireGoogleMaps && ! $mapsSelectionRequired,
                            statePath: $addressStatePath,
                            showGoogleMapsUrlField: ! $mapsSelectionRequired,
                            enableGoogleMapsNormalization: true,
                            enableGoogleMapsRemoteLookup: $shouldRenderLocationPicker && ! $mapsSelectionRequired,
                            includeCountryField: true,
                            showCountryField: false,
                            requireCountryField: true,
                            includeWazeUrlField: $includeWazeUrlField,
                        )]),
                ])
                ->columns($addressStatePath === null ? 2 : 1),
            Section::make(__('Contact'))
                ->schema([
                    SharedFormSchema::contactsRepeater(),
                ]),
            Section::make(__('Social Media'))
                ->schema([
                    SharedFormSchema::socialMediaRepeater(__('Add social media links for this institution')),
                ]),
        ];

        if ($includeMedia) {
            array_splice($components, $mapsSelectionRequired ? 3 : 2, 0, [
                Section::make(__('Media'))
                    ->schema([
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
                            ->image()
                            ->conversion('gallery_thumb')
                            ->responsiveImages()
                            ->helperText(__('Up to 10 photos of the institution'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
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
    ): array {
        $components = self::components(
            includeMedia: false,
            addressStatePath: $addressStatePath,
            includeLocationPicker: $includeLocationPicker,
            includeWazeUrlField: $includeWazeUrlField,
        );

        if ($mediaFields !== []) {
            array_splice($components, 1, 0, [self::directEditMediaSection($mediaFields)]);
        }

        return $components;
    }

    /**
     * @param  list<string>  $mediaFields
     */
    public static function directEditMediaSection(array $mediaFields): Section
    {
        $components = [];

        if (in_array('logo', $mediaFields, true)) {
            $components[] = SpatieMediaLibraryFileUpload::make('logo')
                ->label(__('Logo'))
                ->collection('logo')
                ->image()
                ->imageEditor()
                ->conversion('thumb')
                ->helperText(__('Institution logo'))
                ->deletable(false)
                ->columnSpanFull();
        }

        if (in_array('cover', $mediaFields, true)) {
            $components[] = SpatieMediaLibraryFileUpload::make('cover')
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
                ->helperText(__('Header or banner image'))
                ->deletable(false)
                ->columnSpanFull();
        }

        if (in_array('gallery', $mediaFields, true)) {
            $components[] = SpatieMediaLibraryFileUpload::make('gallery')
                ->label(__('Gallery'))
                ->collection('gallery')
                ->multiple()
                ->reorderable()
                ->image()
                ->conversion('gallery_thumb')
                ->responsiveImages()
                ->helperText(__('Up to 10 photos of the institution'))
                ->columnSpanFull();
        }

        return Section::make(__('Media'))
            ->schema($components)
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

    /**
     * @return array<string, string>
     */
    private static function institutionNameTypeOptions(): array
    {
        return collect(InstitutionNameType::cases())
            ->mapWithKeys(fn (InstitutionNameType $type): array => [$type->value => __($type->label())])
            ->all();
    }
}
