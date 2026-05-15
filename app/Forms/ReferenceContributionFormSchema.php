<?php

namespace App\Forms;

use App\Enums\ReferencePartType;
use App\Enums\ReferenceType;
use App\Models\Reference;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

class ReferenceContributionFormSchema
{
    /**
     * @return array<int, Component>
     */
    public static function components(bool $includeMedia = false): array
    {
        $components = [
            Section::make(__('Reference Details'))
                ->schema([
                    TextInput::make('title')
                        ->label(__('Title'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('author')
                        ->label(__('Author'))
                        ->maxLength(255),
                    Select::make('type')
                        ->label(__('Reference Type'))
                        ->options(ReferenceType::class)
                        ->default(ReferenceType::Book->value)
                        ->required()
                        ->live(),
                    Select::make('parent_reference_id')
                        ->label(__('Parent Book'))
                        ->helperText(__('Use this when the reference is a specific jilid, bahagian, or volume of another book.'))
                        ->options(fn (): array => Reference::query()
                            ->where('type', ReferenceType::Book->value)
                            ->whereNull('parent_reference_id')
                            ->orderBy('title')
                            ->pluck('title', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->live()
                        ->visible(fn (Get $get): bool => in_array($get('type'), [ReferenceType::Book, ReferenceType::Book->value], true))
                        ->dehydrated(fn (Get $get): bool => in_array($get('type'), [ReferenceType::Book, ReferenceType::Book->value], true)),
                    Select::make('part_type')
                        ->label(__('Part Type'))
                        ->options(ReferencePartType::class)
                        ->default(ReferencePartType::Jilid->value)
                        ->visible(fn (Get $get): bool => filled($get('parent_reference_id')))
                        ->dehydrated(fn (Get $get): bool => filled($get('parent_reference_id'))),
                    TextInput::make('part_number')
                        ->label(__('Part Number'))
                        ->placeholder('2')
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => filled($get('parent_reference_id')))
                        ->dehydrated(fn (Get $get): bool => filled($get('parent_reference_id'))),
                    TextInput::make('part_label')
                        ->label(__('Part Label'))
                        ->helperText(__('Optional display label, e.g. Jilid 2 or Bahagian Akhir.'))
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => filled($get('parent_reference_id')))
                        ->dehydrated(fn (Get $get): bool => filled($get('parent_reference_id'))),
                    TextInput::make('publication_year')
                        ->label(__('Publication Year'))
                        ->maxLength(255),
                    TextInput::make('publisher')
                        ->label(__('Publisher'))
                        ->maxLength(255),
                    Textarea::make('description')
                        ->label(__('Description'))
                        ->rows(5)
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make(__('Links'))
                ->schema([
                    SharedFormSchema::socialMediaRepeater('Add relevant links for this reference (e.g. YouTube video, Blog article, etc.)'),
                ]),
        ];

        if ($includeMedia) {
            array_splice($components, 1, 0, [
                Section::make(__('Imagery'))
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('front_cover')
                            ->label(__('Front Cover'))
                            ->collection('front_cover')
                            ->image()
                            ->imageEditor()
                            ->conversion('thumb')
                            ->responsiveImages(),
                        SpatieMediaLibraryFileUpload::make('back_cover')
                            ->label(__('Back Cover'))
                            ->collection('back_cover')
                            ->image()
                            ->imageEditor()
                            ->conversion('thumb')
                            ->responsiveImages(),
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
}
