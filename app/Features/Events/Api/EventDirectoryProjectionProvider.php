<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * A pure value contract for institution event counts and next-event identifiers.
 * The Events owner constructs the correlated SQL without exposing mutable builders.
 */
interface EventDirectoryProjectionProvider
{
    public function institutionDirectoryEventColumns(): EventDirectorySqlProjection;
}
