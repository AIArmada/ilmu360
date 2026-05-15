<?php

namespace App\Filament\Resources\AiUsageLogs\Tables;

use App\Models\AiUsageLog;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AiUsageLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Logged At')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('operation')
                    ->badge()
                    ->formatStateUsing(
                        fn (string $state): string => str($state)->replace('_', ' ')->headline()->toString()
                    )
                    ->sortable(),

                TextColumn::make('provider')
                    ->badge()
                    ->placeholder('N/A')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('model')
                    ->placeholder('N/A')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('meta.detected_tier')
                    ->label('Tier')
                    ->placeholder('Default')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('input_tokens')
                    ->label('Input')
                    ->numeric()
                    ->sortable()
                    ->placeholder('N/A')
                    ->summarize(Sum::make()->label('Σ Input')),

                TextColumn::make('output_tokens')
                    ->label('Output')
                    ->numeric()
                    ->sortable()
                    ->placeholder('N/A')
                    ->summarize(Sum::make()->label('Σ Output')),

                TextColumn::make('cache_write_input_tokens')
                    ->label('Cache Write')
                    ->numeric()
                    ->sortable()
                    ->placeholder('N/A')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('cache_read_input_tokens')
                    ->label('Cache Read')
                    ->numeric()
                    ->sortable()
                    ->placeholder('N/A')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reasoning_tokens')
                    ->label('Reasoning')
                    ->numeric()
                    ->sortable()
                    ->placeholder('N/A')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total_tokens')
                    ->label('Total')
                    ->numeric()
                    ->sortable()
                    ->placeholder('N/A')
                    ->summarize(Sum::make()->label('Σ Tokens')),

                TextColumn::make('cost_usd')
                    ->label('Cost (USD)')
                    ->sortable()
                    ->placeholder('N/A')
                    ->formatStateUsing(
                        fn (mixed $state): string => is_numeric($state)
                            ? '$'.number_format((float) $state, 6)
                            : 'N/A'
                    )
                    ->summarize(
                        Sum::make()
                            ->label('Σ Cost')
                            ->formatStateUsing(
                                fn (mixed $state): string => '$'.number_format((float) $state, 6)
                            )
                    ),

                TextColumn::make('meta.cost_unavailable_reason')
                    ->label('Cost Status')
                    ->formatStateUsing(
                        fn (mixed $state): string => is_string($state) && $state !== ''
                            ? str($state)->replace('_', ' ')->headline()->toString()
                            : 'Calculated'
                    )
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('meta.cost_source')
                    ->label('Cost Source')
                    ->formatStateUsing(
                        fn (mixed $state): string => is_string($state) && $state !== ''
                            ? str($state)->replace('_', ' ')->headline()->toString()
                            : 'Unknown'
                    )
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('user.email')
                    ->label('User')
                    ->placeholder('Guest/System')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('invocation_id')
                    ->label('Invocation')
                    ->copyable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('operation')
                    ->options(
                        fn (): array => AiUsageLog::query()
                            ->select('operation')
                            ->whereNotNull('operation')
                            ->distinct()
                            ->orderBy('operation')
                            ->pluck('operation', 'operation')
                            ->map(
                                fn (string $operation): string => str($operation)->replace('_', ' ')->headline()->toString()
                            )
                            ->all()
                    ),

                SelectFilter::make('provider')
                    ->options(
                        fn (): array => AiUsageLog::query()
                            ->select('provider')
                            ->whereNotNull('provider')
                            ->where('provider', '!=', '')
                            ->distinct()
                            ->orderBy('provider')
                            ->pluck('provider', 'provider')
                            ->all()
                    ),

                SelectFilter::make('model')
                    ->options(
                        fn (): array => AiUsageLog::query()
                            ->select('model')
                            ->whereNotNull('model')
                            ->where('model', '!=', '')
                            ->distinct()
                            ->orderBy('model')
                            ->pluck('model', 'model')
                            ->all()
                    ),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
