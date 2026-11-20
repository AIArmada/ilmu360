<?php

declare(strict_types=1);

namespace App\Features\Discovery\Api\Orm;

use App\Features\Discovery\Api\Data\EventListData;

/**
 * Public event-card payload boundary.
 *
 * Consumers pass a hydrated owner event model and receive the immutable list
 * payload the public HTTP and MCP surfaces serialize.
 *
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Providers/AppServiceProvider.php, Events/Queries, Events/EntryPoints, Discovery/Services, Discovery/EntryPoints
 */
interface EventListPayloads
{
    public function forEvent(object $event): EventListData;
}
