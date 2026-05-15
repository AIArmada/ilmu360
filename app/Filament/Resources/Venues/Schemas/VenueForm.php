<?php

namespace App\Filament\Resources\Venues\Schemas;

use App\Enums\ContactCategory;
use App\Enums\ContactType;
use App\Enums\SocialMediaPlatform;
use App\Enums\VenueType;
use App\Forms\SharedFormSchema;
use App\Support\Location\FederalTerritoryLocation;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class VenueForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Details')
                    ->components([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('type')
                            ->options(VenueType::class)
                            ->required(),
                        Select::make('status')
                            ->options([
                                'unverified' => 'Unverified',
                                'pending' => 'Pending',
                                'verified' => 'Verified',
                                'rejected' => 'Rejected',
                            ])
                            ->required()
                            ->default('verified'),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        TextInput::make('slug')
                            ->required(fn (string $operation): bool => $operation !== 'create')
                            ->hidden(fn (string $operation): bool => $operation === 'create')
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                    ])
                    ->columns(2),
                Section::make('Contact')
                    ->components([
                        Repeater::make('contacts')
                            ->relationship()
                            ->schema([
                                Select::make('category')
                                    ->label(__('Category'))
                                    ->options(ContactCategory::class)
                                    ->required()
                                    ->live(),
                                ...SharedFormSchema::contactValueFields(),
                                Select::make('type')
                                    ->label(__('Type'))
                                    ->options(ContactType::class)
                                    ->default(ContactType::Main)
                                    ->required(),
                                Toggle::make('is_public')
                                    ->label(__('Public'))
                                    ->default(true),
                            ])
                            ->columns(4)
                            ->orderColumn('order_column')
                            ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => SharedFormSchema::normalizeContactRowsForFill($data))
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => SharedFormSchema::normalizeContactRowsForSave($data))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => SharedFormSchema::normalizeContactRowsForSave($data))
                            ->itemLabel(fn (array $state): string => SharedFormSchema::contactItemLabel($state)),
                    ]),
                Section::make('Location')
                    ->relationship('address')
                    ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => SharedFormSchema::hydrateAddressFormState($data))
                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => SharedFormSchema::prepareAddressPersistenceData($data))
                    ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => SharedFormSchema::prepareAddressPersistenceData($data))
                    ->components([
                        Select::make('country_id')
                            ->relationship('country', 'name')
                            ->default(132) // Malaysia
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (Set $set) {
                                $set('state_id', null);
                                $set('district_id', null);
                                $set('subdistrict_id', null);
                            }),
                        Select::make('state_id')
                            ->label('State')
                            ->relationship('state', 'name', fn ($query, $get) => $query->where('country_id', $get('country_id')))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (Set $set) {
                                $set('district_id', null);
                                $set('subdistrict_id', null);
                            }),
                        Select::make('district_id')
                            ->label('District')
                            ->relationship('district', 'name', fn ($query, $get) => $query->where('state_id', $get('state_id')))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('subdistrict_id', null))
                            ->visible(fn (Get $get): bool => filled($get('state_id')) && ! FederalTerritoryLocation::isFederalTerritoryStateId($get('state_id'))),
                        Select::make('subdistrict_id')
                            ->label('Subdistrict / Mukim')
                            ->options(fn (Get $get): array => SharedFormSchema::subdistrictOptionsForSelection($get('state_id'), $get('district_id')))
                            ->searchable()
                            ->preload()
                            ->visible(fn (Get $get): bool => SharedFormSchema::shouldShowSubdistrictField($get('state_id'), $get('district_id'))),
                        TextInput::make('line1')
                            ->maxLength(255),
                        TextInput::make('line2')
                            ->maxLength(255),
                        TextInput::make('postcode')
                            ->maxLength(16),
                        Hidden::make('google_display_name'),
                        Hidden::make('google_resolution_source'),
                        Hidden::make('google_resolution_status'),
                        Hidden::make('google_resolution_fingerprint'),
                        Hidden::make('google_resolution_message'),
                        TextInput::make('lat')
                            ->numeric()
                            ->minValue(-90)
                            ->maxValue(90),
                        TextInput::make('lng')
                            ->numeric()
                            ->minValue(-180)
                            ->maxValue(180),
                        SharedFormSchema::googleMapsUrlField(defaultHelperText: 'Paste the full Google Maps link from your browser'),
                        TextInput::make('google_place_id')
                            ->label('Google Place ID')
                            ->maxLength(255)
                            ->helperText('Optional: For advanced integrations'),
                        TextInput::make('waze_url')
                            ->label('Waze URL')
                            ->url()
                            ->maxLength(255),
                    ])
                    ->columns(2),
                Section::make('Facilities')
                    ->components([
                        CheckboxList::make('facilities')
                            ->options([
                                'parking' => 'Parking',
                                'oku' => 'OKU Access',
                                'women_section' => 'Women Section',
                                'ablution_area' => 'Ablution Area',
                            ])
                            ->columns(2)
                            ->afterStateHydrated(function (CheckboxList $component, $state): void {
                                if (! is_array($state)) {
                                    return;
                                }

                                $component->state(array_keys(array_filter($state)));
                            })
                            ->dehydrateStateUsing(fn ($state): array => array_fill_keys($state ?? [], true))
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
                Section::make('Media')
                    ->components([
                        SpatieMediaLibraryFileUpload::make('cover')
                            ->collection('cover')
                            ->image()
                            ->imageEditor()
                            ->imageAspectRatio('16:9')
                            ->automaticallyOpenImageEditorForAspectRatio()
                            ->imageEditorAspectRatioOptions(['16:9'])
                            ->automaticallyCropImagesToAspectRatio()
                            ->responsiveImages()
                            ->conversion('banner')
                            ->helperText('Cover venue image (recommended: 1200x675)'),
                        SpatieMediaLibraryFileUpload::make('gallery')
                            ->collection('gallery')
                            ->multiple()
                            ->image()
                            ->imageEditor()
                            ->reorderable()
                            ->responsiveImages()
                            ->conversion('thumb')
                            ->helperText('Additional images for gallery'),
                    ])
                    ->columns(2),
                Section::make('Social Media')
                    ->components([
                        Repeater::make('socialMedia')
                            ->relationship()
                            ->schema([
                                Select::make('platform')
                                    ->options(SocialMediaPlatform::class)
                                    ->searchable()
                                    ->required()
                                    ->columnSpan(1),
                                TextInput::make('username')
                                    ->label('Username / Handle')
                                    ->requiredWithout('url')
                                    ->placeholder('@username / https://...')
                                    ->columnSpan(1),
                                TextInput::make('url')
                                    ->label('URL')
                                    ->requiredWithout('username')
                                    ->url()
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->orderColumn('order_column')
                            ->itemLabel(fn (array $state): ?string => $state['platform'] instanceof SocialMediaPlatform
                                ? $state['platform']->getLabel()
                                : ($state['platform'] ?? null)),
                    ]),
            ]);
    }
}
