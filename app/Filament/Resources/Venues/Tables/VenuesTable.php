<?php

declare(strict_types=1);

namespace App\Filament\Resources\Venues\Tables;

use App\Filament\Resources\Venues\VenueResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class VenuesTable
{
    public static function configure(Table $table): Table
    {
        // Configuration for Venues Table
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('cover')
                    ->label('Image')
                    ->collection('cover')
                    ->conversion('thumb')
                    ->square()
                    ->size(56),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state): string => match ($state) {
                        'verified' => 'success',
                        'pending' => 'warning',
                        'rejected' => 'danger',
                        'unverified' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                ToggleColumn::make('is_active')
                    ->label('Active'),
                TextColumn::make('address.state.name')
                    ->label('State')
                    ->sortable(),
                TextColumn::make('address.district.name')
                    ->label('District')
                    ->sortable(),
                TextColumn::make('address.city.name')
                    ->label('City')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'main_hall' => 'Main Hall',
                        'seminar_room' => 'Seminar Room',
                        'classroom' => 'Classroom',
                        'meeting_room' => 'Meeting Room',
                        'auditorium' => 'Auditorium',
                        'field' => 'Field',
                        'foyer' => 'Foyer',
                        'other' => 'Other',
                    ]),
                SelectFilter::make('status')
                    ->options([
                        'unverified' => 'Unverified',
                        'pending' => 'Pending',
                        'verified' => 'Verified',
                        'rejected' => 'Rejected',
                    ]),
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->recordUrl(fn ($record): string => VenueResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
