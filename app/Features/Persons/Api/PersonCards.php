<?php

declare(strict_types=1);

namespace App\Features\Persons\Api;

/**
 * Public person-card payload boundary.
 *
 * Ordinary callers pass scalar ids and receive immutable card payloads; the
 * owner performs the hydration and follow-state lookup.
 */
interface PersonCards
{
    /**
     * @param  list<string>  $personIds
     * @return array<string, array<string, mixed>> Card payloads keyed by person id.
     */
    public function forIds(array $personIds, ?string $accountId = null): array;

    /**
     * @return array<string, mixed>|null
     */
    public function forId(string $personId, ?string $accountId = null): ?array;

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
