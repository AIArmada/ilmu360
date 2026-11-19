<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Legacy builder boundary for public schedule composition.
 *
 * The returned builder is an opaque Event query owned by this feature. The
 * contract exists only while Discovery composes homepage-only predicates;
 * event SQL never moves into the caller.
 */
interface EventScheduleQuery
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function publicEventQuery(?string $query, array $filters): mixed;
}
