<?php

declare(strict_types=1);

namespace App\Filament\Resources\Districts\Tables;

use App\Filament\Resources\Districts\DistrictResource;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DistrictsTable
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
                TextColumn::make('state.name')
                    ->label('State')
                    ->searchable(),
                TextColumn::make('country.name')
                    ->label('Country')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('country_code')
                    ->label('Country Code')
                    ->toggleable(),
                TextColumn::make('subdistricts_count')
                    ->label('Subdistricts')
                    ->counts('subdistricts')
                    ->sortable(),
                TextColumn::make('addresses_count')
                    ->label('Addresses')
                    ->counts('addresses')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('country_id')
                    ->relationship('country', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),
                SelectFilter::make('state_id')
                    ->relationship('state', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DistrictResource::makeDeleteAction(),
            ])
            ->recordUrl(fn (object $record): string => DistrictResource::getUrl('edit', ['record' => $record]));
    }
}
