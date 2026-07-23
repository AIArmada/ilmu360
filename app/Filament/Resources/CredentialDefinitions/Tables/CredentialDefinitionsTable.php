<?php

namespace App\Filament\Resources\CredentialDefinitions\Tables;

use App\Enums\CredentialType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CredentialDefinitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('short_form')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('field')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('credential_type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('language_code')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('credential_type')
                    ->options(CredentialType::class),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
