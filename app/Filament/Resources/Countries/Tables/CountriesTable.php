<?php

declare(strict_types=1);

namespace App\Filament\Resources\Countries\Tables;

use App\Filament\Resources\Countries\CountryResource;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CountriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('iso2')
                    ->label('ISO 2')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('iso3')
                    ->label('ISO 3')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('phone_code')
                    ->label('Phone')
                    ->toggleable(),
                TextColumn::make('region')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('subregion')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('status')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('states_count')
                    ->label('States')
                    ->counts('states')
                    ->sortable(),
                TextColumn::make('cities_count')
                    ->label('Cities')
                    ->counts('cities')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('addresses_count')
                    ->label('Addresses')
                    ->counts('addresses')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('status')
                    ->label('Active'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                CountryResource::makeDeleteAction(),
            ])
            ->recordUrl(fn (object $record): string => CountryResource::getUrl('edit', ['record' => $record]));
    }
}
