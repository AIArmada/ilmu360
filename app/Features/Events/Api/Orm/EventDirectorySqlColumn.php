<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

/**
 * Immutable owner-registered SQL fragment with its positional bindings.
 *
 * @internal SQL projection seam for the institution directory listing.
 *
 * Consumers: Institutions InstitutionDirectoryQuery.
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
