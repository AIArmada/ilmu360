<?php

namespace App\Filament\Resources\References\Tables;

use App\Enums\ReferenceType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReferencesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('front_cover')
                    ->label('Cover')
                    ->collection('front_cover')
                    ->conversion('thumb')
                    ->square()
                    ->size(56),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('author')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ReferenceType::tryFrom($state)?->getLabel() ?? ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        ReferenceType::Book->value => 'warning',
                        ReferenceType::Article->value => 'info',
                        ReferenceType::Video->value => 'danger',
                        ReferenceType::Other->value => 'gray',
                        default => 'primary',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                ToggleColumn::make('is_active')
                    ->label('Active'),
                IconColumn::make('is_canonical')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'verified' => 'Verified',
                    ]),
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
