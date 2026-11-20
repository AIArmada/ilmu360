<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Event category payload boundary for list/detail/engagement surfaces.
 */
interface EventCategoryPayloads
{
    /**
     * @return list<array{id: string, code: string, name: string, path: string, is_primary: bool}>
     */
    public function forEventPayload(object $event): array;
}
