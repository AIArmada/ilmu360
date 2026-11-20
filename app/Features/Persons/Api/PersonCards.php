<?php

declare(strict_types=1);

namespace App\Features\Persons\Api;

/**
 * Public person-card payload boundary.
 *
 * Consumers pass a hydrated owner person model and receive the immutable card
 * payload the public HTTP and MCP surfaces serialize.
 */
interface PersonCards
{
    /**
     * @return array<string, mixed>
     */
    public function forPerson(object $person, ?string $accountId = null): array;

    /**
     * Aggregate-search person cards with the owner's public visibility and
     * follow-state rules.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function forSearch(
        string $query,
        ?string $accountId = null,
        int $limit = 4,
    ): array;
}
