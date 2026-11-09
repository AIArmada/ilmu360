<?php

namespace App\Features\Inspirations\EntryPoints\Filament\Resources\Inspirations\Tables;

use App\Features\Inspirations\Actions\DeleteInspirationsAction;
use App\Features\Inspirations\Actions\ToggleInspirationStatusAction;
use App\Features\Inspirations\Enums\InspirationCategory;
use App\Features\Inspirations\Models\Inspiration;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class InspirationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('main')
                    ->label('Image')
                    ->collection('main')
                    ->conversion('thumb')
                    ->size(56),

                TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn (InspirationCategory $state): string => $state->label())
                    ->color(fn (InspirationCategory $state): string => $state->color())
                    ->icon(fn (InspirationCategory $state): string => $state->icon())
                    ->sortable(),

                TextColumn::make('locale')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => config("app.supported_locales.{$state}", $state))
                    ->sortable(),

                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(50),

                TextColumn::make('content')
                    ->formatStateUsing(fn (Inspiration $record): string => $record->contentPreviewText())
                    ->limit(80)
                    ->toggleable(),

                TextColumn::make('source')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(InspirationCategory::class)
                    ->native(false),

                SelectFilter::make('locale')
                    ->options(config('app.supported_locales'))
                    ->native(false),

                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ])
                    ->native(false),
            ])
            ->defaultSort('category')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('toggleStatus')
                        ->label('Toggle Status')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->action(function (Collection $records): void {
                            $records->each(function ($record): void {
                                if (! $record instanceof Inspiration) {
                                    return;
                                }

                                app(ToggleInspirationStatusAction::class)->handle($record);
                            });
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete')
                        ->using(function (Collection $records): void {
                            app(DeleteInspirationsAction::class)->handle($records->pluck('id')->all());
                        }),
                ]),
            ]);
    }
}
