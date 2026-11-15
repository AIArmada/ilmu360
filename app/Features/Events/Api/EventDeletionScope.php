<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

interface EventDeletionScope
{
    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function whileDeleting(string $eventId, callable $callback): mixed;

    public function isDeleting(string $eventId): bool;
}
