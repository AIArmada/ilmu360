<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

/**
 * Event category payload boundary for list/detail/engagement surfaces.
 *
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Providers/AppServiceProvider.php, Events/Queries, Events/EntryPoints, Discovery/EntryPoints
 */
interface EventCategoryPayloads
{
    /**
     * @return list<array{id: string, code: string, name: string, path: string, is_primary: bool}>
     */
    public function forEventPayload(object $event): array;
}
