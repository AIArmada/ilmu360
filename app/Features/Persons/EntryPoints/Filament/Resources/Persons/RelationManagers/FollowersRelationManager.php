<?php

declare(strict_types=1);

namespace App\Features\Persons\EntryPoints\Filament\Resources\Persons\RelationManagers;

use AIArmada\Engagement\Enums\FollowStatus;
use AIArmada\FilamentEngagement\RelationManagers\FollowersRelationManager as PackageFollowersRelationManager;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

class FollowersRelationManager extends PackageFollowersRelationManager
{
    #[\Override]
    protected static string $relationship = 'follows';

    #[\Override]
    protected static ?string $title = 'Followers';

    protected function modifyFollowersQuery(Builder $query): Builder
    {
        return $query->where('status', FollowStatus::Active->value);
    }

    protected function getFollowersColumns(): array
    {
        return [
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
        ];
    }

    protected function getFollowersFilters(): array
    {
        return [];
    }

    protected function getFollowersHeaderActions(): array
    {
        return [];
    }

    protected function getFollowersRecordActions(): array
    {
        return [];
    }
}
