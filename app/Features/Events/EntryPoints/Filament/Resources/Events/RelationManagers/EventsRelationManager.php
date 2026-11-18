<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Filament\Resources\Events\RelationManagers;

use AIArmada\CommerceSupport\Filament\Concerns\VerifiesRelationManagerOwnerContext;
use App\Features\Events\EntryPoints\Filament\Resources\Events\EventResource;
use Filament\Resources\RelationManagers\RelationManager;

class EventsRelationManager extends RelationManager
{
    use VerifiesRelationManagerOwnerContext;

    #[\Override]
    protected static string $relationship = 'events';

    #[\Override]
    protected static ?string $title = 'Events';

    #[\Override]
    protected static ?string $relatedResource = EventResource::class;
}
