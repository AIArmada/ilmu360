<?php

namespace App\Forms;

use App\Actions\Venues\GenerateVenueSlugAction;
use App\Enums\VenueType;
use App\Models\Venue;
use App\Support\Location\GooglePlacesConfiguration;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class VenueFormSchema
{
    /**
     * Shared createOptionForm for Venue selects.
     *
     * @return array<int, Component>
     */
    public static function createOptionForm(bool $includeLocationPicker = false): array
    {
        return [
            TextInput::make('name')
                ->label(__('Nama Lokasi'))
                ->required()
                ->maxLength(255)
                ->placeholder(__('cth: Dewan Serbaguna, Dewan A')),

            Select::make('type')
                ->label(__('Jenis Lokasi'))
                ->required()
                ->options(VenueType::class)
                ->placeholder(__('Select type...')),

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
                ->label(__('Galeri'))
                ->collection('gallery')
                ->multiple()
                ->image()
                ->imageEditor()
                ->conversion('thumb')
                ->responsiveImages()
                ->maxFiles(10)
                ->helperText(__('Sehingga 10 gambar lokasi')),

            ...self::addressSchema(includeLocationPicker: $includeLocationPicker),

            SharedFormSchema::socialMediaRepeater('Add social media links for this venue'),
        ];
    }

    /**
     * Shared createOptionUsing callback for Venue selects.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createOptionUsing(array $data, ?Schema $schema = null): string
    {
        $addressData = is_array($data['address'] ?? null) ? $data['address'] : $data;

        $venue = Venue::create([
            'name' => $data['name'],
            'slug' => app(GenerateVenueSlugAction::class)->handle((string) $data['name'], $addressData),
            'type' => $data['type'],
            'status' => 'pending',
        ]);

        // Save media uploads (cover, gallery) via Filament's relationship-saving mechanism
        $schema?->model($venue)->saveRelationships();

        SharedFormSchema::createAddressFromData($venue, $addressData, allowCountryOnly: true);
        SharedFormSchema::createSocialMediaFromData($venue, $data);

        return (string) $venue->getKey();
    }

    /**
     * @return array<int, Component>
     */
    private static function addressSchema(bool $includeLocationPicker): array
    {
        $publicCountryId = SharedFormSchema::preferredPublicCountryId();

        if (! $includeLocationPicker) {
            return SharedFormSchema::addressFields(
                requireGoogleMaps: true,
                includeCountryField: true,
                showCountryField: false,
                defaultCountryId: $publicCountryId,
                requireCountryField: true,
            );
        }

        $shouldRenderLocationPicker = GooglePlacesConfiguration::isEnabled();

        return [
            Group::make([
                ...($shouldRenderLocationPicker
                    ? [
                        View::make('filament.schemas.components.institution-location-picker')
                            ->viewData([
                                'mapsApiKey' => GooglePlacesConfiguration::apiKey(),
                                'title' => __('Find the venue location'),
                                'description' => __('Search like a ride-hailing destination, pick the correct place, then confirm it on the map before saving.'),
                                'searchLabel' => __('Search for a venue or address'),
                            ]),
                    ]
                    : []),
                ...SharedFormSchema::addressFields(
                    requireGoogleMaps: true,
                    showGoogleMapsUrlField: ! $shouldRenderLocationPicker,
                    enableGoogleMapsNormalization: true,
                    enableGoogleMapsRemoteLookup: $shouldRenderLocationPicker,
                    includeCountryField: true,
                    showCountryField: false,
                    defaultCountryId: $publicCountryId,
                    requireCountryField: true,
                ),
            ])
                ->statePath('address')
                ->columns(2),
        ];
    }
}
