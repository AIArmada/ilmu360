<?php

namespace App\Forms;

use App\Enums\InstitutionType;
use App\Support\Location\GooglePlacesConfiguration;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
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
    ): array {
        $shouldRenderLocationPicker = self::shouldRenderLocationPicker($includeLocationPicker, $addressStatePath);
        $publicCountryId = SharedFormSchema::preferredPublicCountryId();

        $components = [
            Section::make(__('Institution Profile'))
                ->schema([
                    Select::make('type')
                        ->label(__('Institution Type'))
                        ->options(InstitutionType::class)
                        ->required(),
                    TextInput::make('name')
                        ->label(__('Institution Name'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('nickname')
                        ->label(__('Nickname'))
                        ->maxLength(255)
                        ->helperText(__('Optional nickname, e.g. Masjid Biru')),
                    RichEditor::make('description')
                        ->label(__('Description'))
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make(__('Address'))
                ->schema([
                    ...($shouldRenderLocationPicker
                        ? [
                            View::make('filament.schemas.components.institution-location-picker')
                                ->statePath($addressStatePath)
                                ->viewData([
                                    'mapsApiKey' => GooglePlacesConfiguration::apiKey(),
                                ]),
                        ]
                        : []),
                    ...($addressStatePath === null
                        ? SharedFormSchema::addressFields(
                            requireGoogleMaps: $requireGoogleMaps,
                            showGoogleMapsUrlField: true,
                            enableGoogleMapsNormalization: true,
                            enableGoogleMapsRemoteLookup: $shouldRenderLocationPicker,
                            includeCountryField: true,
                            showCountryField: false,
                            defaultCountryId: $publicCountryId,
                            requireCountryField: true,
                        )
                        : [SharedFormSchema::addressGroup(
                            requireGoogleMaps: $requireGoogleMaps,
                            statePath: $addressStatePath,
                            showGoogleMapsUrlField: true,
                            enableGoogleMapsNormalization: true,
                            enableGoogleMapsRemoteLookup: $shouldRenderLocationPicker,
                            includeCountryField: true,
                            showCountryField: false,
                            defaultCountryId: $publicCountryId,
                            requireCountryField: true,
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
            array_splice($components, 2, 0, [
                Section::make(__('Media'))
                    ->schema([
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
                            ->columnSpanFull(),
                        SpatieMediaLibraryFileUpload::make('gallery')
                            ->label(__('Gallery'))
                            ->collection('gallery')
                            ->multiple()
                            ->reorderable()
                            ->image()
                            ->conversion('gallery_thumb')
                            ->responsiveImages()
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
    ): array {
        $components = self::components(
            includeMedia: false,
            addressStatePath: $addressStatePath,
            includeLocationPicker: $includeLocationPicker,
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
                ->columnSpanFull();
        }

        return Section::make(__('Media'))
            ->schema($components)
            ->columns(['default' => 1, 'sm' => 2]);
    }

    private static function shouldRenderLocationPicker(bool $includeLocationPicker, ?string $addressStatePath): bool
    {
        return $includeLocationPicker
            && $addressStatePath !== null
            && GooglePlacesConfiguration::isEnabled();
    }
}
