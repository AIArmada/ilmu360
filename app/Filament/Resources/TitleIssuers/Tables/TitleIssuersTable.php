<?php

namespace App\Filament\Resources\TitleIssuers\Tables;

use App\Enums\IssuerType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TitleIssuersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('issuer_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('issuer_type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('country.name')
                    ->label(__('Country'))
                    ->sortable(),
                TextColumn::make('institution.name')
                    ->label(__('Institution'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('issuer_type')
                    ->options(IssuerType::class),
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
