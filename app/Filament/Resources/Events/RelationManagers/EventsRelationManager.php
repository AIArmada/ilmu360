<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\RelationManagers;

use AIArmada\CommerceSupport\Filament\Concerns\VerifiesRelationManagerOwnerContext;
use App\Filament\Resources\Events\EventResource;
use Filament\Resources\RelationManagers\RelationManager;

class EventsRelationManager extends RelationManager
{
    use VerifiesRelationManagerOwnerContext;

    protected static string $relationship = 'events';

    protected static ?string $title = 'Events';

    protected static ?string $relatedResource = EventResource::class;
}
