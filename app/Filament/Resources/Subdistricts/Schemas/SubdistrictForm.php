<?php

namespace App\Filament\Resources\Subdistricts\Schemas;

use App\Models\Country;
use App\Support\Location\FederalTerritoryLocation;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class SubdistrictForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Subdistrict Details')
                    ->components([
                        Select::make('country_id')
                            ->relationship('country', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                $set('state_id', null);
                                $set('district_id', null);
                                $set('country_code', Country::query()->find($state)?->iso2);
                            }),
                        Select::make('state_id')
                            ->relationship(
                                name: 'state',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query
                                    ->when(filled($get('country_id')), fn (Builder $query): Builder => $query->where('country_id', $get('country_id')))
                            )
                            ->required()
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('district_id', null);
                            })
                            ->disabled(fn (Get $get): bool => blank($get('country_id'))),
                        Select::make('district_id')
                            ->relationship(
                                name: 'district',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query
                                    ->when(filled($get('state_id')), fn (Builder $query): Builder => $query->where('state_id', $get('state_id')))
                            )
                            ->required(fn (Get $get): bool => ! FederalTerritoryLocation::isFederalTerritoryStateId($get('state_id')))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->disabled(fn (Get $get): bool => blank($get('state_id')))
                            ->visible(fn (Get $get): bool => filled($get('state_id')) && ! FederalTerritoryLocation::isFederalTerritoryStateId($get('state_id'))),
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
