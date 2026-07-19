<?php

declare(strict_types=1);

namespace App\Filament\Resources\Spaces\Tables;

use App\Filament\Resources\Spaces\SpaceResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SpacesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('capacity')
                    ->numeric()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('visibility')
                    ->badge()
                    ->sortable(),
                TextColumn::make('institutions_count')
                    ->label('Institutions')
                    ->counts('institutions')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->recordUrl(fn ($record): string => SpaceResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
