<?php

declare(strict_types=1);

namespace App\Support\Search;

final class FuzzySearchPolicy
{
    public static function isComparable(
        string $search,
        string $candidate,
        ?int $maximumDistance = null,
    ): bool {
        if ($search === '' || $candidate === '') {
            return false;
        }

        if (mb_substr($search, 0, 1) !== mb_substr($candidate, 0, 1)) {
            return false;
        }

        return $maximumDistance === null
            || levenshtein($search, $candidate) <= $maximumDistance;
    }
}
