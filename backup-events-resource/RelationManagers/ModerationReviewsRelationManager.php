<?php

namespace App\Filament\Resources\Events\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ModerationReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'moderationReviews';

    #[\Override]
    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('decision')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'needs_changes' => 'warning',
                        'cancelled' => 'danger',
                        'reconsidered' => 'info',
                        'remoderated' => 'info',
                        'reverted_to_draft' => 'gray',
                        'pending_review' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->title()->toString())
                    ->sortable(),
                TextColumn::make('reason_code')
                    ->label('Reason')
                    ->formatStateUsing(fn (?string $state): string => $state ? str($state)->replace('_', ' ')->title()->toString() : '-')
                    ->placeholder('-'),
                TextColumn::make('moderator.name')
                    ->label('Moderator')
                    ->placeholder('System'),
                TextColumn::make('note')
                    ->limit(60)
                    ->tooltip(fn ($record): ?string => $record->note)
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
