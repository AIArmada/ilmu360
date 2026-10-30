<?php

namespace App\Filament\Resources\Persons\Tables;

use App\Enums\SpeakerStatus;
use App\Models\Person;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PersonsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('media'))
            ->columns([
                SpatieMediaLibraryImageColumn::make('avatar')
                    ->label('Avatar')
                    ->collection('avatar')
                    ->conversion('thumb')
                    ->circular()
                    ->size(48)
                    ->defaultImageUrl(fn (Person $record): string => $record->default_avatar_url),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('followers_count')
                    ->label('Followers')
                    ->counts('followers')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('speaker_status')
                    ->label('Speaker')
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('email')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('phone')
                    ->toggleable(isToggledHiddenByDefault: true),
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
                        'rejected' => 'Rejected',
                        'inactive' => 'Inactive',
                    ]),
                SelectFilter::make('speaker_status')
                    ->label('Speaker')
                    ->options(SpeakerStatus::class)
                    ->placeholder('All'),
                SelectFilter::make('avatar')
                    ->label('Avatar')
                    ->options([
                        'has' => 'With avatar',
                        'missing' => 'Without avatar',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'has' => $query->whereHas('media', fn (Builder $mediaQuery): Builder => $mediaQuery->where('collection_name', 'avatar')),
                        'missing' => $query->whereDoesntHave('media', fn (Builder $mediaQuery): Builder => $mediaQuery->where('collection_name', 'avatar')),
                        default => $query,
                    }),
                SelectFilter::make('profile')
                    ->label('Profile image')
                    ->options([
                        'has' => 'With profile image',
                        'missing' => 'Without profile image',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'has' => $query->whereHas('media', fn (Builder $mediaQuery): Builder => $mediaQuery->where('collection_name', 'profile')),
                        'missing' => $query->whereDoesntHave('media', fn (Builder $mediaQuery): Builder => $mediaQuery->where('collection_name', 'profile')),
                        default => $query,
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
