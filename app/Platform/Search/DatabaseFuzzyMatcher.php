<?php

declare(strict_types=1);

namespace App\Platform\Search;

use AIArmada\CommerceSupport\Support\StringSimilarity;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class DatabaseFuzzyMatcher
{
    public function normalize(string $search): ?string
    {
        $normalized = StringSimilarity::normalize($search);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return list<string>
     */
    public function tokens(string $normalizedSearch, int $minLength = 2): array
    {
        $normalized = $this->normalize($normalizedSearch);

        if ($normalized === null) {
            return [];
        }

        return array_values(array_unique(array_filter(
            explode(' ', $normalized),
            static fn (string $token): bool => mb_strlen($token) >= $minLength,
        )));
    }

    /**
     * @return list<string>
     */
    public function candidatePatterns(string $normalizedSearch): array
    {
        $tokens = array_values(array_unique(array_filter([
            $normalizedSearch,
            ...array_filter(explode(' ', $normalizedSearch), static fn (string $token): bool => mb_strlen($token) >= 3),
        ], static fn (string $token): bool => $token !== '')));

        $patterns = [];

        foreach ($tokens as $token) {
            foreach ($this->patternSources($token) as $patternSource) {
                $pattern = $this->subsequencePattern($patternSource);

                if ($pattern !== null) {
                    $patterns[] = $pattern;
                }
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * @return list<string>
     */
    public function patternSources(string $value): array
    {
        $sources = [$value];

        if (str_contains($value, ' ') || mb_strlen($value) < 5) {
            return $sources;
        }

        return array_values(array_unique([
            ...$sources,
            ...$this->omissionVariants($value),
        ]));
    }

    /**
     * @return list<string>
     */
    public function omissionVariants(string $value): array
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($characters) || count($characters) < 2) {
            return [];
        }

        $variants = [];

        foreach (array_keys($characters) as $index) {
            $variantCharacters = $characters;
            unset($variantCharacters[$index]);

            $variant = implode('', $variantCharacters);

            if ($variant !== '') {
                $variants[] = $variant;
            }
        }

        return array_values(array_unique($variants));
    }

    public function subsequencePattern(string $value): ?string
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($characters) || $characters === []) {
            return null;
        }

        return '%'.implode('%', $characters).'%';
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function isPostgres(Builder $query): bool
    {
        $connection = $query->getConnection();

        return $connection instanceof Connection && $connection->getDriverName() === 'pgsql';
    }

    public function pgTrgmThreshold(): float
    {
        $configured = config('discovery.search.pg_trgm_threshold', 0.25);

        return is_numeric($configured) ? (float) $configured : 0.25;
    }

    /**
     * Apply driver-aware fuzzy candidate prefiltering across one or more columns.
     *
     * Uses pg_trgm (`%` and `word_similarity`) on PostgreSQL and normalized
     * subsequence `LIKE` patterns on SQLite and other drivers.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @return Builder<TModel>
     */
    public function applyColumnsFilter(Builder $query, array $columns, string $normalizedSearch): Builder
    {
        if ($columns === []) {
            return $query;
        }

        if ($this->isPostgres($query)) {
            $threshold = $this->pgTrgmThreshold();

            return $query->where(function (Builder $candidateQuery) use ($columns, $normalizedSearch, $threshold): void {
                foreach ($columns as $column) {
                    $this->orWherePgTrgm($candidateQuery, $column, $normalizedSearch, $threshold);
                }
            });
        }

        $patterns = $this->candidatePatterns($normalizedSearch);

        if ($patterns === []) {
            return $query;
        }

        return $query->where(function (Builder $candidateQuery) use ($columns, $patterns): void {
            foreach ($patterns as $pattern) {
                foreach ($columns as $column) {
                    $candidateQuery->orWhereLike($column, $pattern);
                }
            }
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function orWherePgTrgm(
        Builder $query,
        string $column,
        string $normalizedSearch,
        ?float $threshold = null,
    ): void {
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);
        $resolvedThreshold = $threshold ?? $this->pgTrgmThreshold();

        $query->orWhereRaw(
            "(lower(coalesce({$wrapped}, '')) % ? or word_similarity(?, lower(coalesce({$wrapped}, ''))) >= ?)",
            [$normalizedSearch, $normalizedSearch, $resolvedThreshold],
        );
    }

    public function score(string $normalizedSearch, string $candidate, ?int $maximumDistance = null): float
    {
        if (! FuzzySearchPolicy::isComparable($normalizedSearch, $candidate, $maximumDistance)) {
            return 0.0;
        }

        return StringSimilarity::score($normalizedSearch, $candidate);
    }

    /**
     * @param  list<string>  $candidates
     */
    public function bestScore(
        string $normalizedSearch,
        array $candidates,
        ?int $maximumDistance = null,
        bool $enforceShortQuerySubstringOnTokens = false,
        bool $usePolicy = true,
    ): float {
        $scores = [];
        $isShortQuery = $enforceShortQuerySubstringOnTokens && mb_strlen($normalizedSearch) <= 3;

        foreach ($candidates as $rawCandidate) {
            $candidate = $this->normalize($rawCandidate);

            if ($candidate === null) {
                continue;
            }

            $scores[] = $usePolicy
                ? $this->score($normalizedSearch, $candidate, $maximumDistance)
                : StringSimilarity::score($normalizedSearch, $candidate);

            $tokens = array_values(array_filter(
                explode(' ', $candidate),
                static fn (string $token): bool => mb_strlen($token) >= 2,
            ));

            foreach ($tokens as $token) {
                if ($isShortQuery && ! str_contains($token, $normalizedSearch)) {
                    continue;
                }

                $scores[] = $usePolicy
                    ? $this->score($normalizedSearch, $token, $maximumDistance)
                    : StringSimilarity::score($normalizedSearch, $token);
            }
        }

        return $scores === [] ? 0.0 : max($scores);
    }

    public function minimumScore(string $normalizedSearch): float
    {
        return mb_strlen($normalizedSearch) >= 6 ? 0.80 : 0.70;
    }

    public function maximumDistance(string $normalizedSearch): int
    {
        return mb_strlen($normalizedSearch) >= 5 ? 2 : 1;
    }
}
