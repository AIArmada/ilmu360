<?php

declare(strict_types=1);

namespace App\Platform\Admin\Filament\RelationManagers;

use AIArmada\CommerceSupport\Filament\Concerns\VerifiesRelationManagerOwnerContext;
use App\Platform\Admin\AdminResourceLink;
use Filament\Resources\RelationManagers\RelationManager;

/**
 * Shared events relation manager for records that expose an `events` relation.
 *
 * The related resource is resolved through the admin resource registry, so
 * consuming features do not import the Events Filament entrypoints.
 */
class EventRecordsRelationManager extends RelationManager
{
    use VerifiesRelationManagerOwnerContext;

    #[\Override]
    protected static string $relationship = 'events';

    #[\Override]
    protected static ?string $title = 'Events';

    public static function getRelatedResource(): ?string
    {
        return AdminResourceLink::forMorphAlias('event');
    }
}
