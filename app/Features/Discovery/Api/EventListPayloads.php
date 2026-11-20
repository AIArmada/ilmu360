<?php

declare(strict_types=1);

namespace App\Features\Discovery\Api;

use App\Features\Discovery\Api\Data\EventListData;

/**
 * Public event-card payload boundary.
 *
 * Consumers pass a hydrated owner event model and receive the immutable list
 * payload the public HTTP and MCP surfaces serialize.
 */
interface EventListPayloads
{
    public function forEvent(object $event): EventListData;
}
