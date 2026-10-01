<?php

namespace App\Filament\Resources\References\Tables;

use App\Enums\ReferenceType;
use App\Models\Reference;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReferencesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(self::applyEffectiveAuthors(...))
            ->columns([
                SpatieMediaLibraryImageColumn::make('front_cover')
                    ->label('Cover')
                    ->collection('front_cover')
                    ->conversion('thumb')
                    ->square()
                    ->size(56),
                TextColumn::make('title')
                    ->state(fn (Reference $record): string => $record->displayTitle())
                    ->searchable()
                    ->sortable(),
                TextColumn::make('effective_authors')
                    ->label('Authors')
                    ->state(fn (Reference $record): string => $record->effectiveAuthorNames())
                    ->placeholder('—')
                    ->wrap(),
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
                TextColumn::make('record_kind')->label('Kind')->badge()->sortable(),
                TextColumn::make('edition_number')->label('Edition')->sortable()->toggleable(),
                TextColumn::make('isbn')->label('ISBN')->searchable()->toggleable(),
                TextColumn::make('language')->sortable()->toggleable(),
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
                        'inactive' => 'Inactive',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }

    /**
     * @param  Builder<Reference>  $query
     */
    private static function applyEffectiveAuthors(Builder $query): void
    {
        $query->withEffectiveAuthors();
    }
}
