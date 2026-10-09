<?php

declare(strict_types=1);

namespace App\Filament\Resources\CommunityReleases\Tables;

use App\Filament\Resources\CommunityReleases\CommunityReleaseResource;
use App\Models\CommunityRelease;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CommunityReleasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('period_end', 'desc')
            ->columns([
                TextColumn::make('release_key')
                    ->label('Month')
                    ->searchable()
                    ->url(fn (CommunityRelease $record): string => CommunityReleaseResource::getUrl('view', ['record' => $record])),
                TextColumn::make('period_start')->label('From')->date(),
                TextColumn::make('period_end')->label('To')->date(),
                TextColumn::make('sharer_count')->label('Sharers')->numeric()->sortable(),
                TextColumn::make('participant_count')->label('Participants')->numeric()->sortable(),
                TextColumn::make('published_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
