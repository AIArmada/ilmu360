<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Filament;

/**
 * Owner-published admin events relation manager.
 *
 * Consumers register the owner's relation manager class without importing the
 * Events Filament entrypoints.
 */
interface AdminEventsRelationManager
{
    /**
     * @return class-string
     */
    public function relationManager(): string;
}
