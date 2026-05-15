<?php

namespace App\Filament\Resources\References\Schemas;

use App\Enums\ReferencePartType;
use App\Enums\ReferenceType;
use App\Enums\SocialMediaPlatform;
use App\Models\Reference;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ReferenceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Reference Details')
                    ->components([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('author')
                            ->maxLength(255),
                        Select::make('type')
                            ->options(ReferenceType::class)
                            ->default(ReferenceType::Book->value)
                            ->required()
                            ->live(),
                        Select::make('parent_reference_id')
                            ->label('Parent Book')
                            ->helperText('Select a root book when this reference represents a specific jilid, bahagian, or volume.')
                            ->options(fn (?Reference $record): array => Reference::query()
                                ->where('type', ReferenceType::Book->value)
                                ->whereNull('parent_reference_id')
                                ->when($record instanceof Reference && $record->exists, fn ($query) => $query->whereKeyNot($record->getKey()))
                                ->orderBy('title')
                                ->pluck('title', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->live()
                            ->visible(fn (Get $get): bool => in_array($get('type'), [ReferenceType::Book, ReferenceType::Book->value], true))
                            ->dehydrated(fn (Get $get): bool => in_array($get('type'), [ReferenceType::Book, ReferenceType::Book->value], true)),
                        Select::make('part_type')
                            ->label('Part Type')
                            ->options(ReferencePartType::class)
                            ->default(ReferencePartType::Jilid->value)
                            ->visible(fn (Get $get): bool => filled($get('parent_reference_id')))
                            ->dehydrated(fn (Get $get): bool => filled($get('parent_reference_id'))),
                        TextInput::make('part_number')
                            ->label('Part Number')
                            ->placeholder('2')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => filled($get('parent_reference_id')))
                            ->dehydrated(fn (Get $get): bool => filled($get('parent_reference_id'))),
                        TextInput::make('part_label')
                            ->label('Part Label')
                            ->helperText('Optional display label, e.g. Jilid 2 or Bahagian Akhir.')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => filled($get('parent_reference_id')))
                            ->dehydrated(fn (Get $get): bool => filled($get('parent_reference_id'))),
                        TextInput::make('publication_year')
                            ->maxLength(255),
                        TextInput::make('publisher')
                            ->maxLength(255),
                        Toggle::make('is_canonical')
                            ->label('Canonical / Official')
                            ->helperText('Is this a standard reference?'),
                        Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'verified' => 'Verified',
                            ])
                            ->default('verified')
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])->columns(2),
                Section::make('Imagery')
                    ->components([
                        SpatieMediaLibraryFileUpload::make('front_cover')
                            ->label('Front Cover')
                            ->collection('front_cover')
                            ->image()
                            ->imageEditor()
                            ->conversion('thumb')
                            ->responsiveImages(),
                        SpatieMediaLibraryFileUpload::make('back_cover')
                            ->label('Back Cover')
                            ->collection('back_cover')
                            ->image()
                            ->imageEditor()
                            ->conversion('thumb')
                            ->responsiveImages(),
                        SpatieMediaLibraryFileUpload::make('gallery')
                            ->label('Gallery')
                            ->collection('gallery')
                            ->multiple()
                            ->image()
                            ->imageEditor()
                            ->conversion('gallery_thumb')
                            ->responsiveImages()
                            ->maxFiles(10)
                            ->columnSpanFull(),
                    ])->columns(2),
                Section::make('Links')
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
                            ->collapsible()
                            ->defaultItems(0)
                            ->addActionLabel('Add Link')
                            ->itemLabel(function (array $state): ?string {
                                $platform = $state['platform'] ?? null;

                                if ($platform instanceof SocialMediaPlatform) {
                                    return $platform->getLabel();
                                }

                                if (is_string($platform)) {
                                    return SocialMediaPlatform::tryFrom($platform)?->getLabel() ?? $platform;
                                }

                                return null;
                            }),
                    ]),
                Section::make('Description')
                    ->components([
                        Textarea::make('description')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
