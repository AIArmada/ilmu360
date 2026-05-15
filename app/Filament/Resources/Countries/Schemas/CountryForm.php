<?php

namespace App\Filament\Resources\Countries\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CountryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Country Details')
                    ->components([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('iso2')
                            ->label('ISO 2')
                            ->required()
                            ->minLength(2)
                            ->maxLength(2)
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Str::upper($state) : null),
                        TextInput::make('iso3')
                            ->label('ISO 3')
                            ->required()
                            ->minLength(3)
                            ->maxLength(3)
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Str::upper($state) : null),
                        TextInput::make('phone_code')
                            ->label('Phone Code')
                            ->required()
                            ->maxLength(16),
                        TextInput::make('region')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('subregion')
                            ->required()
                            ->maxLength(255),
                        Toggle::make('status')
                            ->label('Active')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
