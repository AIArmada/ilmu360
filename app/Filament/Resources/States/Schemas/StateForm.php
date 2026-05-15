<?php

namespace App\Filament\Resources\States\Schemas;

use App\Models\Country;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class StateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('State Details')
                    ->components([
                        Select::make('country_id')
                            ->relationship('country', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->live(),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Hidden::make('country_code')
                            ->dehydrated()
                            ->dehydrateStateUsing(fn (Get $get): ?string => Country::query()->find($get('country_id'))?->iso2),
                    ])
                    ->columns(2),
            ]);
    }
}
