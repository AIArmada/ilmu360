<?php

declare(strict_types=1);

namespace App\Filament\Resources\References\RelationManagers;

use App\Filament\Resources\Events\EventResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $relatedResource = EventResource::class;

    public function table(Table $table): Table
    {
        return $table;
    }
}
