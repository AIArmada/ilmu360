<?php

namespace App\Filament\Resources\Venues\Schemas;

use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Contacting\Enums\SocialPlatform;
use App\Enums\VenueType;
use App\Forms\SharedFormSchema;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
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
                                Select::make('type')
                                    ->label(__('Type'))
                                    ->options(ContactMethodType::options())
                                    ->required()
                                    ->live(),
                                ...SharedFormSchema::contactValueFields(),
                                Select::make('purpose')
                                    ->label(__('Purpose'))
                                    ->options(ContactPurpose::options())
                                    ->default(ContactPurpose::General->value)
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
                    ->statePath('address')
                    ->components(SharedFormSchema::addressFields(
                        includeCountryField: true,
                        showCountryField: false,
                        requireCountryField: true,
                    ))
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
                                    ->options(SocialPlatform::options())
                                    ->searchable()
                                    ->required()
                                    ->columnSpan(1),
                                TextInput::make('handle')
                                    ->label('Handle')
                                    ->requiredWithout('url')
                                    ->placeholder('@username / https://...')
                                    ->columnSpan(1),
                                TextInput::make('url')
                                    ->label('URL')
                                    ->requiredWithout('handle')
                                    ->url()
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->orderColumn('order_column')
                            ->itemLabel(fn (array $state): ?string => $state['platform'] instanceof SocialPlatform
                                ? $state['platform']->label()
                                : ($state['platform'] ?? null)),
                    ]),
            ]);
    }
}
