<?php

declare(strict_types=1);

namespace App\Features\Events\Queries;

/** Discovery paginators need event identities, not hydrated occurrence media. */
final readonly class SearchResultRelations
{
    /**
     * @param  array<int|string, mixed>  $relations
     * @return array<int|string, mixed>
     */
    public static function forCards(array $relations): array
    {
        return array_filter(
            $relations,
            static fn (mixed $relation, int|string $key): bool => $key !== 'displayOccurrence.media'
                && $relation !== 'displayOccurrence.media',
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
