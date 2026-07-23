<?php

namespace App\Filament\Resources\Titles\Schemas;

use App\Enums\TitleUsagePosition;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TitleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Title Details'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('short_form')
                            ->label(__('Short Form'))
                            ->maxLength(50)
                            ->placeholder(__('e.g. PhD, Dr., Prof.')),
                        Select::make('category_id')
                            ->label(__('Category'))
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('usage_position')
                            ->label(__('Usage Position'))
                            ->options(TitleUsagePosition::class)
                            ->required(),
                        TextInput::make('language_code')
                            ->label(__('Language Code'))
                            ->maxLength(10)
                            ->placeholder(__('e.g. ms, ar, en')),
                        TextInput::make('sort_order')
                            ->label(__('Sort Order'))
                            ->numeric()
                            ->default(0),
                        Textarea::make('description')
                            ->label(__('Description'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
