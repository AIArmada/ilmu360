<?php

namespace App\Support\Search;

use App\Models\Reference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReferenceSearchService
{
    /**
     * @param  Builder<Reference>  $query
     * @return Builder<Reference>
     */
    public function applySearch(Builder $query, string $search): Builder
    {
        $normalizedSearch = $this->normalizedSearch($search);

        if ($normalizedSearch === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->shouldUseScoutSearch() && app(TypesenseHealthCheckService::class)->isAvailable()) {
            try {
                return $this->applyScoutSearch($query, $normalizedSearch);
            } catch (\Throwable $exception) {
                $this->logScoutFallback('Reference Typesense search failed, falling back to database search', $exception, $normalizedSearch);
            }
        }

        return $this->applyDatabaseSearch($query, $normalizedSearch);
    }

    /**
     * @param  Builder<Reference>  $query
     * @return list<string>
     */
    public function scopedSearchIds(Builder $query, string $search): array
    {
        $normalizedSearch = $this->normalizedSearch($search);

        if ($normalizedSearch === null) {
            return [];
        }

        $model = $query->getModel();
        $keyColumn = $model->qualifyColumn($model->getKeyName());

        $ids = (clone $query)
            ->select($keyColumn)
            ->tap(fn (Builder $builder): Builder => $this->applySearch($builder, $normalizedSearch))
            ->orderBy($model->qualifyColumn('title'))
            ->pluck($keyColumn)
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        if ($ids !== [] || mb_strlen($normalizedSearch) < 3) {
            return $ids;
        }

        return $this->scopedFuzzySearchIds($query, $normalizedSearch, $this->minimumFuzzyScore($normalizedSearch));
    }

    /**
     * @return list<string>
     */
    public function publicSearchIds(string $search): array
    {
        $normalizedSearch = $this->normalizedSearch($search);

        if ($normalizedSearch === null) {
            return [];
        }

        if ($this->shouldUseTypesenseSearch() && app(TypesenseHealthCheckService::class)->isAvailable()) {
            try {
                return $this->searchIdsWithScout($normalizedSearch, [
                    'filter_by' => 'is_active:=true && status:=verified',
                    'num_typos' => 0,
                ]);
            } catch (\Throwable $exception) {
                $this->logScoutFallback('Reference Typesense public search failed, falling back to database search', $exception, $normalizedSearch);
            }
        }

        return $this->publicSearchIdsFromDatabase($normalizedSearch);
    }

    /**
     * @return list<string>
     */
    public function resolvedPublicSearchIds(string $search): array
    {
        $normalizedSearch = $this->normalizedSearch($search);

        if ($normalizedSearch === null) {
            return [];
        }

        $ids = $this->publicSearchIds($normalizedSearch);

        if ($ids !== [] || mb_strlen($normalizedSearch) < 3) {
            return $ids;
        }

        return $this->publicFuzzySearchIds($normalizedSearch);
    }

    /**
     * @return list<string>
     */
    public function publicFuzzySearchIds(string $search): array
    {
        $normalizedSearch = $this->normalizedSearch($search);

        if ($normalizedSearch === null) {
            return [];
        }

        $minimumScore = $this->minimumFuzzyScore($normalizedSearch);

        if ($this->shouldUseTypesenseSearch() && app(TypesenseHealthCheckService::class)->isAvailable()) {
            try {
                return $this->searchIdsWithScout($normalizedSearch, [
                    'filter_by' => 'is_active:=true && status:=verified',
                    'prioritize_exact_match' => true,
                ]);
            } catch (\Throwable $exception) {
                $this->logScoutFallback('Reference Typesense fuzzy search failed, falling back to database fuzzy search', $exception, $normalizedSearch);
            }
        }

        return $this->publicFuzzySearchIdsFromDatabase($normalizedSearch, $minimumScore);
    }

    /**
     * @return list<string>
     */
    private function publicSearchIdsFromDatabase(string $normalizedSearch): array
    {
        return Reference::query()
            ->active()
            ->where('status', 'verified')
            ->select('references.id')
            ->tap(fn (Builder $query): Builder => $this->applyDatabaseSearch($query, $normalizedSearch))
            ->orderBy('references.title')
            ->pluck('references.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function publicFuzzySearchIdsFromDatabase(string $normalizedSearch, float $minimumScore): array
    {
        return Reference::query()
            ->active()
            ->where('status', 'verified')
            ->select(['id', 'title', 'author'])
            ->tap(fn (Builder $query): Builder => $this->applyFuzzyCandidateFilter($query, $normalizedSearch))
            ->tap(fn (Builder $query): Builder => $this->applyFuzzyCandidateOrdering($query, $normalizedSearch))
            ->limit($this->typesenseResultLimit())
            ->get()
            ->map(function (Reference $reference) use ($normalizedSearch): array {
                $candidates = array_values(array_filter([
                    $this->normalizeText((string) $reference->title),
                    $this->normalizeText((string) ($reference->author ?? '')),
                ], static fn (string $candidate): bool => $candidate !== ''));

                $scoreCandidates = [];

                foreach ($candidates as $candidate) {
                    $scoreCandidates[] = $this->fuzzyScore($normalizedSearch, $candidate);

                    $tokens = array_values(array_filter(
                        explode(' ', $candidate),
                        static fn (string $token): bool => mb_strlen($token) >= 2,
                    ));

                    foreach ($tokens as $token) {
                        $scoreCandidates[] = $this->fuzzyScore($normalizedSearch, $token);
                    }
                }

                return [
                    'id' => (string) $reference->id,
                    'score' => $scoreCandidates === [] ? 0.0 : max($scoreCandidates),
                ];
            })
            ->filter(static fn (array $candidate): bool => $candidate['score'] >= $minimumScore)
            ->sortByDesc('score')
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Reference>  $query
     * @return list<string>
     */
    private function scopedFuzzySearchIds(Builder $query, string $normalizedSearch, float $minimumScore): array
    {
        $model = $query->getModel();

        return (clone $query)
            ->reorder()
            ->select([
                $model->qualifyColumn($model->getKeyName()),
                $model->qualifyColumn('title'),
                $model->qualifyColumn('author'),
            ])
            ->tap(fn (Builder $builder): Builder => $this->applyFuzzyCandidateFilter($builder, $normalizedSearch))
            ->tap(fn (Builder $builder): Builder => $this->applyFuzzyCandidateOrdering($builder, $normalizedSearch))
            ->limit($this->typesenseResultLimit())
            ->get()
            ->map(function (Reference $reference) use ($normalizedSearch): array {
                $candidates = array_values(array_filter([
                    $this->normalizeText((string) $reference->title),
                    $this->normalizeText((string) ($reference->author ?? '')),
                ], static fn (string $candidate): bool => $candidate !== ''));

                $scoreCandidates = [];

                foreach ($candidates as $candidate) {
                    $scoreCandidates[] = $this->fuzzyScore($normalizedSearch, $candidate);

                    $tokens = array_values(array_filter(
                        explode(' ', $candidate),
                        static fn (string $token): bool => mb_strlen($token) >= 2,
                    ));

                    foreach ($tokens as $token) {
                        $scoreCandidates[] = $this->fuzzyScore($normalizedSearch, $token);
                    }
                }

                return [
                    'id' => (string) $reference->id,
                    'score' => $scoreCandidates === [] ? 0.0 : max($scoreCandidates),
                ];
            })
            ->filter(static fn (array $candidate): bool => $candidate['score'] >= $minimumScore)
            ->sortByDesc('score')
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Reference>  $query
     * @return Builder<Reference>
     */
    private function applyDatabaseSearch(Builder $query, string $normalizedSearch): Builder
    {
        $operator = DB::connection($query->getModel()->getConnectionName())->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $collapsedWildcardSearch = '%'.str_replace(' ', '%', $normalizedSearch).'%';
        $searchTokens = array_values(array_filter(
            explode(' ', $normalizedSearch),
            static fn (string $token): bool => $token !== '' && (mb_strlen($token) >= 2 || ctype_digit($token))
        ));

        return $query->where(function (Builder $innerQuery) use ($normalizedSearch, $operator, $collapsedWildcardSearch, $searchTokens): void {
            $innerQuery
                ->where('references.title', $operator, "%{$normalizedSearch}%")
                ->orWhere('references.title', $operator, $collapsedWildcardSearch)
                ->orWhere('references.author', $operator, "%{$normalizedSearch}%")
                ->orWhere('references.publisher', $operator, "%{$normalizedSearch}%")
                ->orWhere('references.description', $operator, "%{$normalizedSearch}%")
                ->orWhere('references.part_type', $operator, "%{$normalizedSearch}%")
                ->orWhere('references.part_label', $operator, "%{$normalizedSearch}%")
                ->orWhere('references.part_number', $operator, "%{$normalizedSearch}%");

            if (count($searchTokens) < 2) {
                return;
            }

            $innerQuery->orWhere(function (Builder $tokenQuery) use ($searchTokens, $operator): void {
                foreach ($searchTokens as $token) {
                    $tokenQuery->where(function (Builder $singleTokenQuery) use ($token, $operator): void {
                        $singleTokenQuery
                            ->where('references.title', $operator, "%{$token}%")
                            ->orWhere('references.author', $operator, "%{$token}%")
                            ->orWhere('references.publisher', $operator, "%{$token}%")
                            ->orWhere('references.description', $operator, "%{$token}%")
                            ->orWhere('references.part_type', $operator, "%{$token}%")
                            ->orWhere('references.part_label', $operator, "%{$token}%")
                            ->orWhere('references.part_number', $operator, "%{$token}%");
                    });
                }
            });
        });
    }

    /**
     * @param  Builder<Reference>  $query
     * @return Builder<Reference>
     */
    private function applyFuzzyCandidateFilter(Builder $query, string $normalizedSearch): Builder
    {
        $patterns = $this->fuzzyCandidatePatterns($normalizedSearch);

        if ($patterns === []) {
            return $query;
        }

        $operator = DB::connection($query->getModel()->getConnectionName())->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';

        return $query->where(function (Builder $candidateQuery) use ($operator, $patterns): void {
            foreach ($patterns as $pattern) {
                $candidateQuery
                    ->orWhere('references.title', $operator, $pattern)
                    ->orWhere('references.author', $operator, $pattern);
            }
        });
    }

    /**
     * @param  Builder<Reference>  $query
     * @return Builder<Reference>
     */
    private function applyFuzzyCandidateOrdering(Builder $query, string $normalizedSearch): Builder
    {
        $wrappedTitleColumn = $query->getQuery()->getGrammar()->wrap($query->getModel()->qualifyColumn('title'));

        return $query
            ->orderByRaw(
                "case when lower(coalesce({$wrappedTitleColumn}, '')) = ? then 0 when lower(coalesce({$wrappedTitleColumn}, '')) like ? then 1 else 2 end",
                [$normalizedSearch, $normalizedSearch.'%'],
            )
            ->orderByRaw("length(coalesce({$wrappedTitleColumn}, ''))")
            ->orderBy('references.title')
            ->orderBy('references.id');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    protected function searchIdsWithScout(string $search, array $options = []): array
    {
        if ($this->scoutDriver() === 'database') {
            return Reference::search($search)
                ->query(fn (Builder $query): Builder => $query->limit($this->typesenseResultLimit()))
                ->get()
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();
        }

        $rawResults = Reference::search($search)
            ->options([
                'query_by' => 'title,author,search_text',
                'per_page' => $this->typesenseResultLimit(),
                ...$options,
            ])
            ->raw();

        /** @var array<int, array<string, mixed>> $hits */
        $hits = is_array($rawResults) && is_array($rawResults['hits'] ?? null)
            ? $rawResults['hits']
            : [];

        return collect($hits)
            ->pluck('document.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    protected function shouldUseScoutSearch(): bool
    {
        return in_array($this->scoutDriver(), ['typesense', 'database'], true);
    }

    /**
     * @param  Builder<Reference>  $query
     * @return Builder<Reference>
     */
    protected function applyScoutSearch(Builder $query, string $search): Builder
    {
        $ids = $this->searchIdsWithScout($search, [
            'num_typos' => 0,
        ]);

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($query->getModel()->qualifyColumn('id'), $ids);
    }

    protected function shouldUseTypesenseSearch(): bool
    {
        return $this->scoutDriver() === 'typesense';
    }

    protected function typesenseResultLimit(): int
    {
        return max(50, (int) (config('scout.typesense.max_total_results') ?? 250));
    }

    protected function logScoutFallback(string $message, \Throwable $exception, string $search): void
    {
        Log::warning($message, [
            'error' => $exception->getMessage(),
            'search' => $search,
        ]);
    }

    protected function scoutDriver(): string
    {
        return (string) config('scout.driver');
    }

    public function normalizedSearch(string $search): ?string
    {
        $normalized = $this->normalizeText($search);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return list<string>
     */
    private function fuzzyCandidatePatterns(string $normalizedSearch): array
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
    private function fuzzyPatternSources(string $value): array
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
    private function fuzzyOmissionVariants(string $value): array
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

    private function fuzzySubsequencePattern(string $value): ?string
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($characters) || $characters === []) {
            return null;
        }

        return '%'.implode('%', $characters).'%';
    }

    private function fuzzyScore(string $search, string $candidate): float
    {
        if (! $this->fuzzyComparable($search, $candidate)) {
            return 0.0;
        }

        return $this->similarityScore($search, $candidate);
    }

    private function fuzzyComparable(string $search, string $candidate): bool
    {
        if ($search === '' || $candidate === '') {
            return false;
        }

        if (mb_substr($search, 0, 1) !== mb_substr($candidate, 0, 1)) {
            return false;
        }

        return levenshtein($search, $candidate) <= $this->maximumFuzzyDistance($search);
    }

    private function minimumFuzzyScore(string $search): float
    {
        return mb_strlen($search) >= 6 ? 0.80 : 0.70;
    }

    private function maximumFuzzyDistance(string $search): int
    {
        return mb_strlen($search) >= 5 ? 2 : 1;
    }

    private function normalizeText(string $value): string
    {
        return (string) Str::of($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s]+/u', ' ')
            ->replaceMatches('/\s+/u', ' ')
            ->trim();
    }

    private function similarityScore(string $search, string $candidate): float
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
}
