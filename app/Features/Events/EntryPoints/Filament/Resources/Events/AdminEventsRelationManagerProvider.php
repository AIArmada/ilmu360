<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Filament\Resources\Events;

use App\Features\Events\Api\Filament\AdminEventsRelationManager;
use App\Features\Events\EntryPoints\Filament\Resources\Events\RelationManagers\EventsRelationManager;

final readonly class AdminEventsRelationManagerProvider implements AdminEventsRelationManager
{
    public function relationManager(): string
    {
        return EventsRelationManager::class;
    }
}
