<?php

declare(strict_types=1);

namespace App\Shared\Directory;

use AIArmada\CommerceSupport\Support\StringSimilarity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Canonical person search-text projection used by both read queries and the
 * Directory-owned search projection writes.
 */
final class PersonSearchText
{
    public static function searchableName(?string $name): string
    {
        return StringSimilarity::normalize((string) $name);
    }

    public static function searchableText(Model $person): string
    {
        $names = $person->relationLoaded('names')
            ? $person->getRelation('names')
            : $person->getRelationValue('names');

        /** @var list<string> $alternativeNames */
        $alternativeNames = $names instanceof Collection
            ? $names->pluck('full_name')->filter(static fn (mixed $name): bool => is_string($name))->values()->all()
            : [];

        return self::searchableName(implode(' ', array_filter([
            (string) $person->getAttribute('formatted_name'),
            ...$alternativeNames,
        ])));
    }

    /**
     * @return list<string>
     */
    public static function terms(?string $name): array
    {
        $searchableName = self::searchableName($name);

        if ($searchableName === '') {
            return [];
        }

        /** @var list<string> $terms */
        $terms = collect(explode(' ', $searchableName))
            ->filter(static fn (string $term): bool => $term !== '')
            ->unique()
            ->values()
            ->all();

        return $terms;
    }
}
