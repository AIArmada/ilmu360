<?php

declare(strict_types=1);

namespace App\Filament\Resources\States\Tables;

use App\Filament\Resources\States\StateResource;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StatesTable
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
                TextColumn::make('country.name')
                    ->label('Country')
                    ->searchable(),
                TextColumn::make('country_code')
                    ->label('Country Code')
                    ->toggleable(),
                TextColumn::make('districts_count')
                    ->label('Districts')
                    ->counts('districts')
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
                SelectFilter::make('country_id')
                    ->relationship('country', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                StateResource::makeDeleteAction(),
            ])
            ->recordUrl(fn (object $record): string => StateResource::getUrl('edit', ['record' => $record]));
    }
}
