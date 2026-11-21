<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Immutable owner-registered SQL fragment with its positional bindings.
 *
 * Public immutable correlated SQL expression for the institution directory.
 */
final readonly class EventDirectorySqlColumn
{
    /**
     * @param  list<mixed>  $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings,
    ) {}
}
