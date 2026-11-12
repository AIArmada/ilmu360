<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\RelationManagers;

use AIArmada\Engagement\Enums\FollowStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FollowersRelationManager extends RelationManager
{
    #[\Override]
    protected static string $relationship = 'follows';

    #[\Override]
    protected static ?string $title = 'Followers';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', FollowStatus::Active->value))
            ->columns([
                TextColumn::make('follower.name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('follower.email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Followed At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
