<?php

namespace App\Forms;

use App\Actions\Institutions\GenerateInstitutionSlugAction;
use App\Actions\Membership\AddMemberToSubject;
use App\Enums\InstitutionType;
use App\Models\Institution;
use App\Models\User;
use App\Support\Location\GooglePlacesConfiguration;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class InstitutionFormSchema
{
    /**
     * Shared createOptionForm for Institution selects.
     *
     * @return array<int, Component>
     */
    public static function createOptionForm(bool $includeLocationPicker = false): array
    {
        return [
            TextInput::make('name')
                ->label(__('Institution Name'))
                ->required()
                ->maxLength(255)
                ->placeholder(__('e.g., Masjid Al-Falah, Surau An-Nur')),

            TextInput::make('nickname')
                ->label(__('Nickname'))
                ->maxLength(255)
                ->helperText(__('Optional nickname, e.g. Masjid Biru')),

            Select::make('type')
                ->label(__('Institution Type'))
                ->required()
                ->options(InstitutionType::class)
                ->placeholder(__('Select type...')),

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

            ...self::addressSchema(includeLocationPicker: $includeLocationPicker),

            SharedFormSchema::socialMediaRepeater('Add social media links for this institution'),
        ];
    }

    /**
     * Shared createOptionUsing callback for Institution selects.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createOptionUsing(array $data, ?Schema $schema = null): string
    {
        $addressData = is_array($data['address'] ?? null) ? $data['address'] : $data;

        $institution = Institution::create([
            'name' => $data['name'],
            'nickname' => is_string($data['nickname'] ?? null) && trim($data['nickname']) !== ''
                ? trim($data['nickname'])
                : null,
            'slug' => app(GenerateInstitutionSlugAction::class)->handle((string) $data['name'], $addressData),
            'type' => $data['type'],
            'description' => $data['description'] ?? null,
            'status' => 'pending',
        ]);

        $creator = auth()->user();

        if ($creator instanceof User) {
            app(AddMemberToSubject::class)->handle($institution, $creator);
        }

        // Save media uploads (cover, gallery) via Filament's relationship-saving mechanism
        $schema?->model($institution)->saveRelationships();

        SharedFormSchema::createContactsFromData($institution, $data);
        SharedFormSchema::createAddressFromData($institution, $addressData, allowCountryOnly: true);
        SharedFormSchema::createSocialMediaFromData($institution, $data);
        app(GenerateInstitutionSlugAction::class)->syncInstitutionSlug($institution);

        return (string) $institution->getKey();
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
                                'title' => __('Find the institution location'),
                                'description' => __('Search like a ride-hailing destination, pick the correct place, then confirm it on the map before saving.'),
                                'searchLabel' => __('Search for an institution or address'),
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
