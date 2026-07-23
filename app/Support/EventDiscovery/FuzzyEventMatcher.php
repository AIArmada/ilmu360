<?php

declare(strict_types=1);

namespace App\Support\EventDiscovery;

use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Str;

final class FuzzyEventMatcher
{
    public function normalizeForSimilarity(string $value): string
    {
        return (string) Str::of($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s]+/u', ' ')
            ->replaceMatches('/\s+/u', ' ')
            ->trim();
    }

    /**
     * @param  Builder<Event>  $queryBuilder
     * @return Builder<Event>
     */
    public function applyFuzzyTitleCandidateFilter(Builder $queryBuilder, string $normalizedSearch): Builder
    {
        $patterns = $this->fuzzyCandidatePatterns($normalizedSearch);

        if ($patterns === []) {
            return $queryBuilder->limit($this->fuzzyCandidateLimit());
        }

        $operator = $this->databaseLikeOperator();

        $queryBuilder->where(function (Builder $candidateQuery) use ($operator, $patterns): void {
            foreach ($patterns as $index => $pattern) {
                $method = $index === 0 ? 'where' : 'orWhere';

                $candidateQuery->{$method}('events.title', $operator, $pattern);
            }
        });

        return $queryBuilder->limit($this->fuzzyCandidateLimit());
    }

    /**
     * @param  Builder<Event>  $queryBuilder
     * @return Builder<Event>
     */
    public function applyFuzzyTitleCandidateOrdering(Builder $queryBuilder, string $normalizedSearch): Builder
    {
        return $queryBuilder
            ->orderByRaw(
                "case when lower(coalesce(events.title, '')) = ? then 0 when lower(coalesce(events.title, '')) like ? then 1 when lower(coalesce(events.title, '')) like ? then 2 else 3 end",
                [$normalizedSearch, $normalizedSearch.'%', '%'.$normalizedSearch.'%']
            )
            ->orderByRaw("length(coalesce(events.title, ''))")
            ->orderBy('events.title')
            ->orderBy('starts_at')
            ->orderBy('events.id');
    }

    /**
     * @return list<string>
     */
    public function fuzzyCandidatePatterns(string $normalizedSearch): array
    {
        $tokens = array_values(array_unique(array_filter([
            $normalizedSearch,
            ...array_filter(explode(' ', $normalizedSearch), static fn (string $token): bool => mb_strlen($token) >= 3),
        ], static fn (string $token): bool => $token !== '')));

        $patterns = [];

        foreach ($tokens as $token) {
            foreach ($this->fuzzyPatternSources($token) as $patternSource) {
                $pattern = $this->fuzzySubsequencePattern($patternSource);

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
    public function fuzzyPatternSources(string $value): array
    {
        $sources = [$value];

        if (str_contains($value, ' ') || mb_strlen($value) < 5) {
            return $sources;
        }

        return array_values(array_unique([
            ...$sources,
            ...$this->fuzzyOmissionVariants($value),
        ]));
    }

    /**
     * @return list<string>
     */
    public function fuzzyOmissionVariants(string $value): array
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

    public function fuzzySubsequencePattern(string $value): ?string
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($characters) || $characters === []) {
            return null;
        }

        return '%'.implode('%', $characters).'%';
    }

    public function fuzzyCandidateLimit(): int
    {
        return 250;
    }

    public function similarityScore(string $search, string $candidate): float
    {
        if ($search === '' || $candidate === '') {
            return 0.0;
        }

        $distance = levenshtein($search, $candidate);
        $maxLength = max(mb_strlen($search), mb_strlen($candidate));
        $distanceScore = $maxLength > 0 ? 1 - ($distance / $maxLength) : 0.0;

        similar_text($search, $candidate, $similarityPercent);
        $similarityScore = $similarityPercent / 100;

        return max($distanceScore, $similarityScore);
    }

    public function eventSimilarityScore(string $normalizedSearch, Event $event): float
    {
        $title = trim((string) $event->title);

        if ($title === '') {
            return 0.0;
        }

        $normalizedCandidate = $this->normalizeForSimilarity($title);

        if ($normalizedCandidate === '') {
            return 0.0;
        }

        $scoreCandidates = [];
        $scoreCandidates[] = $this->similarityScore($normalizedSearch, $normalizedCandidate);

        /** @var list<string> $candidateTokens */
        $candidateTokens = array_values(array_filter(
            explode(' ', $normalizedCandidate),
            static fn (string $token): bool => mb_strlen($token) >= 2
        ));

        foreach ($candidateTokens as $token) {
            $scoreCandidates[] = $this->similarityScore($normalizedSearch, $token);
        }

        return max($scoreCandidates);
    }

    private function databaseLikeOperator(): string
    {
        return Event::query()->getGrammar() instanceof PostgresGrammar ? 'ILIKE' : 'LIKE';
    }
}
