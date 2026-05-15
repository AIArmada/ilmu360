<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subdistricts\Tables;

use App\Filament\Resources\Subdistricts\SubdistrictResource;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SubdistrictsTable
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
                TextColumn::make('district.name')
                    ->label('District')
                    ->searchable(),
                TextColumn::make('state.name')
                    ->label('State')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('country.name')
                    ->label('Country')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('country_code')
                    ->label('Country Code')
                    ->toggleable(),
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
                SelectFilter::make('district_id')
                    ->relationship('district', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                SubdistrictResource::makeDeleteAction(),
            ])
            ->recordUrl(fn (object $record): string => SubdistrictResource::getUrl('edit', ['record' => $record]));
    }
}
