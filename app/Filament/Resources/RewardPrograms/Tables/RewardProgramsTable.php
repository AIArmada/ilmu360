<?php

declare(strict_types=1);

namespace App\Filament\Resources\RewardPrograms\Tables;

use App\Filament\Resources\RewardPrograms\RewardProgramResource;
use App\Models\RewardProgram;
use App\Support\Rewards\RewardAdminPresenter;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RewardProgramsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->url(fn (RewardProgram $record): string => RewardProgramResource::getUrl('view', ['record' => $record])),
                TextColumn::make('family_key')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('mode')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => RewardAdminPresenter::programModeOptions()[(string) $state->value] ?? (string) $state->value),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => RewardAdminPresenter::statusLabel($state))
                    ->color(fn (mixed $state): string => RewardAdminPresenter::statusColor($state)),
                TextColumn::make('created_at')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(RewardAdminPresenter::programStatusOptions()),
                SelectFilter::make('mode')->options(RewardAdminPresenter::programModeOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
