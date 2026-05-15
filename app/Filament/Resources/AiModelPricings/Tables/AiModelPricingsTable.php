<?php

namespace App\Filament\Resources\AiModelPricings\Tables;

use App\Models\AiModelPricing;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AiModelPricingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('model_pattern')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('operation')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->headline()->toString())
                    ->sortable(),

                TextColumn::make('tier')
                    ->placeholder('Default')
                    ->sortable(),

                TextColumn::make('priority')
                    ->numeric()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('input_per_million')
                    ->label('Input/M')
                    ->money('USD')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('output_per_million')
                    ->label('Output/M')
                    ->money('USD')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('per_request')
                    ->money('USD')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('per_image')
                    ->money('USD')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('provider')
                    ->options(fn (): array => AiModelPricing::query()
                        ->whereNotNull('provider')
                        ->distinct()
                        ->orderBy('provider')
                        ->pluck('provider', 'provider')
                        ->all()),

                SelectFilter::make('operation')
                    ->options(fn (): array => AiModelPricing::query()
                        ->whereNotNull('operation')
                        ->distinct()
                        ->orderBy('operation')
                        ->pluck('operation', 'operation')
                        ->map(fn (string $value): string => str($value)->replace('_', ' ')->headline()->toString())
                        ->all()),

                SelectFilter::make('is_active')
                    ->options([
                        '1' => 'Active',
                        '0' => 'Inactive',
                    ]),
            ])
            ->defaultSort('priority', 'asc')
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
