<?php

declare(strict_types=1);

namespace App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback\Tables;

use App\Features\Feedback\Enums\AppFeedbackCategory;
use App\Features\Feedback\Enums\AppFeedbackStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AppFeedbackTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['reporter', 'handler']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->limit(80),
                TextColumn::make('description')
                    ->searchable()
                    ->limit(80)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('category')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('reporter.email')
                    ->label('Reporter')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('github_escalation_status')
                    ->label('GitHub')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('last_state_change_at')
                    ->label('Aging')
                    ->since()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(AppFeedbackCategory::options()),
                SelectFilter::make('status')
                    ->options(AppFeedbackStatus::options())
                    ->multiple()
                    ->default([AppFeedbackStatus::New->value, AppFeedbackStatus::Triaged->value]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
