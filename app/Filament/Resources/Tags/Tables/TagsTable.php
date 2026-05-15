<?php

namespace App\Filament\Resources\Tags\Tables;

use App\Enums\TagType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TagsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => TagType::from($state)->label())
                    ->color(fn (string $state): string => TagType::from($state)->color())
                    ->icon(fn (string $state): string => TagType::from($state)->icon())
                    ->sortable(),

                TextColumn::make('name')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function ($query) use ($search) {
                        $query->where('name->ms', 'like', "%{$search}%")
                            ->orWhere('name->en', 'like', "%{$search}%");
                    }))
                    ->formatStateUsing(function ($record): string {
                        $ms = $record->getTranslation('name', 'ms');
                        $en = $record->getTranslation('name', 'en');

                        if ($ms === $en || ! $en) {
                            return $ms;
                        }

                        return "{$ms} / {$en}";
                    })
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'verified',
                    ])
                    ->sortable(),

                TextColumn::make('order_column')
                    ->label('Order')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(TagType::class)
                    ->native(false),

                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'verified' => 'Verified',
                    ])
                    ->native(false),

                Filter::make('user_created')
                    ->label('User Created (Pending)')
                    ->query(fn (Builder $query): Builder => $query->where('status', 'pending'))
                    ->toggle(),
            ])
            ->defaultSort('type')
            ->reorderable('order_column')
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
