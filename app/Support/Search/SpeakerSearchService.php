<?php

namespace App\Support\Search;

use App\Models\Speaker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SpeakerSearchService
{
    private const int PUBLIC_SEARCH_CACHE_TTL = 600;

    private const string PUBLIC_SEARCH_CACHE_VERSION_KEY = 'speaker_search_public_version_v1';

    /**
     * @param  iterable<int, string|\BackedEnum>|string|null  $honorific
     * @param  iterable<int, string|\BackedEnum>|string|null  $preNominal
     * @param  iterable<int, string|\BackedEnum>|string|null  $postNominal
     */
    public function buildSearchableName(
        ?string $name,
        iterable|string|null $honorific = null,
        iterable|string|null $preNominal = null,
        iterable|string|null $postNominal = null,
    ): string {
        $formattedName = Speaker::formatDisplayedName($name, $honorific, $preNominal, $postNominal);

        $rawDecorations = collect([
            ...$this->normalizedStringValues($honorific),
            ...$this->normalizedStringValues($preNominal),
            ...$this->normalizedStringValues($postNominal),

        ])
            ->map(static fn (string $value): string => str_replace(['_', '-'], ' ', $value))
            ->implode(' ');

        return $this->normalizeText(implode(' ', array_filter([
            trim($formattedName),
            trim((string) $name),
            trim($rawDecorations),
        ])));
    }

    /**
     * @param  iterable<int, string|\BackedEnum>|string|null  $honorific
     * @param  iterable<int, string|\BackedEnum>|string|null  $preNominal
     * @param  iterable<int, string|\BackedEnum>|string|null  $postNominal
     * @return list<string>
     */
    public function buildSearchTerms(
        ?string $name,
        iterable|string|null $honorific = null,
        iterable|string|null $preNominal = null,
        iterable|string|null $postNominal = null,
    ): array {
        $searchableName = $this->buildSearchableName($name, $honorific, $preNominal, $postNominal);

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

    /**
     * @param  Builder<Speaker>  $query
     * @return Builder<Speaker>
     */
    public function applyIndexedSearch(Builder $query, string $search): Builder
    {
        $normalizedSearch = $this->normalizedSearch($search);

        if ($normalizedSearch === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->shouldUseScoutSearch() && app(TypesenseHealthCheckService::class)->isAvailable()) {
            try {
                return $this->applyScoutSearch($query, $normalizedSearch);
            } catch (\Throwable $exception) {
                $this->logScoutFallback('Speaker Typesense search failed, falling back to local search', $exception, $normalizedSearch);
            }
        }

        if (! $this->hasSpeakerSearchTermsTable()) {
            return $this->applyLegacySearch($query, $normalizedSearch);
        }

        return $this->applyIndexedSearchWithLocalIndex($query, $normalizedSearch);
    }

    /**
     * @param  Builder<Speaker>  $query
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
            ->tap(fn (Builder $builder): Builder => $this->applyIndexedSearch($builder, $normalizedSearch))
            ->orderBy($model->qualifyColumn('name'))
            ->pluck($keyColumn)
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        if ($ids !== [] || mb_strlen($normalizedSearch) < 3) {
            return $ids;
        }

        return $this->scopedFuzzySearchIds($query, $normalizedSearch);
    }

    /**
     * @param  Builder<Speaker>  $query
     * @return Builder<Speaker>
     */
    private function applyIndexedSearchWithLocalIndex(Builder $query, string $search): Builder
    {
        $searchTokens = $this->searchTokens($search);

        if ($searchTokens === []) {
            return $query->whereRaw('1 = 0');
        }

        $qualifiedSpeakerId = $query->getModel()->qualifyColumn('id');

        return $query->where(function (Builder $speakerQuery) use ($searchTokens, $qualifiedSpeakerId): void {
            foreach ($searchTokens as $token) {
                $speakerQuery->whereExists(function ($termQuery) use ($qualifiedSpeakerId, $token): void {
                    $termQuery->selectRaw('1')
                        ->from('speaker_search_terms')
                        ->whereColumn('speaker_search_terms.speaker_id', $qualifiedSpeakerId)
                        ->where('speaker_search_terms.term', 'like', '%'.$token.'%');
                });
            }
        });
    }

    /**
     * @param  Builder<Speaker>  $query
     * @return Builder<Speaker>
     */
    public function applyPublicCachedSearch(Builder $query, string $search): Builder
    {
        $ids = $this->publicSearchIds($search);

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($query->getModel()->qualifyColumn('id'), $ids);
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

        $cacheKey = sprintf(
            'speaker_search_public:%s:%s',
            $this->publicSearchCacheVersion(),
            md5($normalizedSearch),
        );

        /** @var list<string> $ids */
        $ids = Cache::remember($cacheKey, self::PUBLIC_SEARCH_CACHE_TTL, function () use ($normalizedSearch): array {
            $localIds = $this->publicSearchIdsFromLocalSearch($normalizedSearch);

            if ($this->shouldUseTypesenseSearch() && app(TypesenseHealthCheckService::class)->isAvailable()) {
                try {
                    $scoutIds = $this->searchIdsWithScout($normalizedSearch, [
                        'filter_by' => 'is_active:=true && status:=verified',
                        'num_typos' => 0,
                    ]);

                    return $this->mergeOrderedIds($scoutIds, $localIds);
                } catch (\Throwable $exception) {
                    $this->logScoutFallback('Speaker Typesense public search failed, falling back to local search', $exception, $normalizedSearch);
                }
            }

            return $localIds;
        });

        return $ids;
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
    private function publicSearchIdsFromLocalSearch(string $normalizedSearch): array
    {
        if (! $this->hasSpeakerSearchTermsTable()) {
            return Speaker::query()
                ->active()
                ->where('status', 'verified')
                ->select('speakers.id')
                ->tap(fn (Builder $query): Builder => $this->applyLegacySearch($query, $normalizedSearch))
                ->orderBy('name')
                ->pluck('speakers.id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();
        }

        return Speaker::query()
            ->active()
            ->where('status', 'verified')
            ->select('speakers.id')
            ->tap(fn (Builder $query): Builder => $this->applyIndexedSearchWithLocalIndex($query, $normalizedSearch))
            ->orderBy('name')
            ->pluck('speakers.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
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

        $cacheKey = sprintf(
            'speaker_search_public_fuzzy:%s:%s',
            $this->publicSearchCacheVersion(),
            md5($normalizedSearch),
        );

        /** @var list<string> $ids */
        $ids = Cache::remember($cacheKey, self::PUBLIC_SEARCH_CACHE_TTL, function () use ($normalizedSearch): array {
            if ($this->shouldUseTypesenseSearch() && app(TypesenseHealthCheckService::class)->isAvailable()) {
                try {
                    return $this->searchIdsWithScout($normalizedSearch, [
                        'filter_by' => 'is_active:=true && status:=verified',
                        'prioritize_exact_match' => true,
                    ]);
                } catch (\Throwable $exception) {
                    $this->logScoutFallback('Speaker Typesense fuzzy search failed, falling back to local fuzzy search', $exception, $normalizedSearch);
                }
            }

            $speakerQuery = Speaker::query()
                ->active()
                ->where('status', 'verified')
                ->select(['id'])
                ->tap(fn (Builder $query): Builder => $this->applyFuzzyCandidateFilter($query, $normalizedSearch))
                ->tap(fn (Builder $query): Builder => $this->applyFuzzyCandidateOrdering($query, $normalizedSearch))
                ->limit($this->typesenseResultLimit());

            if ($this->hasSearchableNameColumn()) {
                $speakerQuery->addSelect('searchable_name');
            } else {
                $speakerQuery->addSelect(['name', 'honorific', 'pre_nominal', 'post_nominal']);
            }

            return $speakerQuery
                ->get()
                ->map(function (Speaker $speaker) use ($normalizedSearch): array {
                    $candidate = $this->speakerCandidateSearchableName($speaker);

                    if ($candidate === '') {
                        return ['id' => (string) $speaker->id, 'score' => 0.0];
                    }

                    $scoreCandidates = [
                        $this->fuzzyComparable($normalizedSearch, $candidate)
                            ? $this->similarityScore($normalizedSearch, $candidate)
                            : 0.0,
                    ];

                    $candidateTokens = array_values(array_filter(
                        explode(' ', $candidate),
                        static fn (string $token): bool => mb_strlen($token) >= 2
                    ));

                    foreach ($candidateTokens as $token) {
                        $scoreCandidates[] = $this->fuzzyComparable($normalizedSearch, $token)
                            ? $this->similarityScore($normalizedSearch, $token)
                            : 0.0;
                    }

                    return [
                        'id' => (string) $speaker->id,
                        'score' => max($scoreCandidates),
                    ];
                })
                ->filter(static fn (array $candidate): bool => $candidate['score'] >= 0.70)
                ->sortByDesc('score')
                ->pluck('id')
                ->values()
                ->all();
        });

        return $ids;
    }

    /**
     * @param  Builder<Speaker>  $query
     * @return list<string>
     */
    private function scopedFuzzySearchIds(Builder $query, string $normalizedSearch): array
    {
        $model = $query->getModel();
        $keyColumn = $model->qualifyColumn($model->getKeyName());

        $speakerQuery = (clone $query)
            ->reorder()
            ->select([$keyColumn])
            ->tap(fn (Builder $builder): Builder => $this->applyFuzzyCandidateFilter($builder, $normalizedSearch))
            ->tap(fn (Builder $builder): Builder => $this->applyFuzzyCandidateOrdering($builder, $normalizedSearch))
            ->limit($this->typesenseResultLimit());

        if ($this->hasSearchableNameColumn()) {
            $speakerQuery->addSelect($model->qualifyColumn('searchable_name'));
        } else {
            $speakerQuery->addSelect([
                $model->qualifyColumn('name'),
                $model->qualifyColumn('honorific'),
                $model->qualifyColumn('pre_nominal'),
                $model->qualifyColumn('post_nominal'),
            ]);
        }

        return $speakerQuery
            ->get()
            ->map(function (Speaker $speaker) use ($normalizedSearch): array {
                $candidate = $this->speakerCandidateSearchableName($speaker);

                if ($candidate === '') {
                    return ['id' => (string) $speaker->id, 'score' => 0.0];
                }

                $scoreCandidates = [
                    $this->fuzzyComparable($normalizedSearch, $candidate)
                        ? $this->similarityScore($normalizedSearch, $candidate)
                        : 0.0,
                ];

                $candidateTokens = array_values(array_filter(
                    explode(' ', $candidate),
                    static fn (string $token): bool => mb_strlen($token) >= 2,
                ));

                foreach ($candidateTokens as $token) {
                    $scoreCandidates[] = $this->fuzzyComparable($normalizedSearch, $token)
                        ? $this->similarityScore($normalizedSearch, $token)
                        : 0.0;
                }

                return [
                    'id' => (string) $speaker->id,
                    'score' => max($scoreCandidates),
                ];
            })
            ->filter(static fn (array $candidate): bool => $candidate['score'] >= 0.70)
            ->sortByDesc('score')
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Speaker>  $query
     * @return Builder<Speaker>
     */
    private function applyFuzzyCandidateFilter(Builder $query, string $normalizedSearch): Builder
    {
        $patterns = $this->fuzzyCandidatePatterns($normalizedSearch);

        if ($patterns === []) {
            return $query;
        }

        $operator = DB::connection($query->getModel()->getConnectionName())->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $columns = $this->hasSearchableNameColumn()
            ? ['speakers.searchable_name']
            : ['speakers.name'];

        return $query->where(function (Builder $candidateQuery) use ($columns, $operator, $patterns): void {
            foreach ($patterns as $pattern) {
                foreach ($columns as $column) {
                    $candidateQuery->orWhere($column, $operator, $pattern);
                }
            }
        });
    }

    /**
     * @param  Builder<Speaker>  $query
     * @return Builder<Speaker>
     */
    private function applyFuzzyCandidateOrdering(Builder $query, string $normalizedSearch): Builder
    {
        $primaryColumn = $this->hasSearchableNameColumn()
            ? 'speakers.searchable_name'
            : 'speakers.name';

        return $query
            ->orderByRaw(
                "case when lower(coalesce({$primaryColumn}, '')) = ? then 0 when lower(coalesce({$primaryColumn}, '')) like ? then 1 else 2 end",
                [$normalizedSearch, $normalizedSearch.'%']
            )
            ->orderByRaw("length(coalesce({$primaryColumn}, ''))")
            ->orderBy($primaryColumn)
            ->orderBy('speakers.id');
    }

    protected function shouldUseScoutSearch(): bool
    {
        return in_array($this->scoutDriver(), ['typesense', 'database'], true);
    }

    protected function shouldUseTypesenseSearch(): bool
    {
        return $this->scoutDriver() === 'typesense';
    }

    /**
     * @param  Builder<Speaker>  $query
     * @return Builder<Speaker>
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

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    protected function searchIdsWithScout(string $search, array $options = []): array
    {
        if ($this->scoutDriver() === 'database') {
            return Speaker::search($search)
                ->query(fn (Builder $query): Builder => $query->limit($this->typesenseResultLimit()))
                ->get()
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();
        }

        $rawResults = Speaker::search($search)
            ->options([
                'query_by' => 'formatted_name,search_text,name,job_title',
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

    public function syncIndex(Speaker $speaker): void
    {
        if (! $this->hasSpeakerSearchTermsTable()) {
            return;
        }

        DB::table('speaker_search_terms')
            ->where('speaker_id', $speaker->getKey())
            ->delete();

        $terms = $this->buildSearchTerms(
            $speaker->name,
            $speaker->honorific,
            $speaker->pre_nominal,
            $speaker->post_nominal,
        );

        if ($terms === []) {
            return;
        }

        DB::table('speaker_search_terms')->insert(
            collect($terms)
                ->map(fn (string $term): array => [
                    'id' => (string) Str::uuid(),
                    'speaker_id' => (string) $speaker->getKey(),
                    'term' => $term,
                ])
                ->all()
        );
    }

    public function syncSpeakerRecord(Speaker $speaker): void
    {
        $this->syncSpeakerRecordWithOptions($speaker, true);
    }

    public function reindexAll(int $chunkSize = 100): int
    {
        $processed = 0;

        Speaker::query()
            ->select(['id', 'name', 'honorific', 'pre_nominal', 'post_nominal'])
            ->orderBy('id')
            ->chunk(max(1, $chunkSize), function ($speakers) use (&$processed): void {
                foreach ($speakers as $speaker) {
                    $this->syncSpeakerRecordWithOptions($speaker, false);
                    $processed++;
                }
            });

        $this->bustPublicSearchCache();

        return $processed;
    }

    public function searchIndexSchemaReady(): bool
    {
        return $this->hasSearchableNameColumn() && $this->hasSpeakerSearchTermsTable();
    }

    private function syncSpeakerRecordWithOptions(Speaker $speaker, bool $bustCache): void
    {
        if ($this->hasSearchableNameColumn()) {
            DB::table('speakers')
                ->where('id', $speaker->getKey())
                ->update([
                    'searchable_name' => $this->buildSearchableName(
                        $speaker->name,
                        $speaker->honorific,
                        $speaker->pre_nominal,
                        $speaker->post_nominal,
                    ),
                ]);
        }

        $this->syncIndex($speaker);

        if ($bustCache) {
            $this->bustPublicSearchCache();
        }
    }

    public function purgeIndex(Speaker $speaker): void
    {
        if (! $this->hasSpeakerSearchTermsTable()) {
            return;
        }

        DB::table('speaker_search_terms')
            ->where('speaker_id', $speaker->getKey())
            ->delete();
    }

    public function purgeSpeakerRecord(Speaker $speaker): void
    {
        $this->purgeIndex($speaker);
        $this->bustPublicSearchCache();
    }

    public function bustPublicSearchCache(): void
    {
        Cache::put(
            self::PUBLIC_SEARCH_CACHE_VERSION_KEY,
            $this->publicSearchCacheVersion() + 1,
            now()->addDays(30),
        );
    }

    public function normalizedSearch(string $search): ?string
    {
        $normalized = $this->normalizeText($search);

        return $normalized === '' ? null : $normalized;
    }

    private function publicSearchCacheVersion(): int
    {
        return (int) Cache::get(self::PUBLIC_SEARCH_CACHE_VERSION_KEY, 1);
    }

    /**
     * @return list<string>
     */
    private function searchTokens(string $search): array
    {
        $normalizedSearch = $this->normalizedSearch($search);

        if ($normalizedSearch === null) {
            return [];
        }

        /** @var list<string> $tokens */
        $tokens = collect(explode(' ', $normalizedSearch))
            ->filter(static fn (string $token): bool => mb_strlen($token) >= 2)
            ->unique()
            ->values()
            ->all();

        return $tokens;
    }

    /**
     * @param  Builder<Speaker>  $query
     * @return Builder<Speaker>
     */
    private function applyLegacySearch(Builder $query, string $search): Builder
    {
        $normalizedSearch = trim($search);

        if ($normalizedSearch === '') {
            return $query;
        }

        $operator = DB::connection($query->getModel()->getConnectionName())->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $collapsedSearch = preg_replace('/\s+/u', ' ', $normalizedSearch) ?? '';
        $collapsedWildcardSearch = '%'.str_replace(' ', '%', $collapsedSearch).'%';
        $searchTokens = array_values(array_filter(explode(' ', $collapsedSearch), static fn (string $token): bool => $token !== ''));

        return $query->where(function (Builder $speakerQuery) use ($operator, $collapsedSearch, $collapsedWildcardSearch, $searchTokens): void {
            $speakerQuery->where('name', $operator, "%{$collapsedSearch}%")
                ->orWhere('name', $operator, $collapsedWildcardSearch);

            foreach ($searchTokens as $token) {
                if (mb_strlen($token) < 2) {
                    continue;
                }

                $speakerQuery->orWhere('name', $operator, "%{$token}%");
            }
        });
    }

    private function speakerCandidateSearchableName(Speaker $speaker): string
    {
        $candidate = $speaker->searchable_name;

        if (is_string($candidate) && $candidate !== '') {
            return $candidate;
        }

        return $this->buildSearchableName(
            $speaker->name,
            $speaker->honorific,
            $speaker->pre_nominal,
            $speaker->post_nominal,
        );
    }

    private function fuzzyComparable(string $search, string $candidate): bool
    {
        if ($search === '' || $candidate === '') {
            return false;
        }

        return mb_substr($search, 0, 1) === mb_substr($candidate, 0, 1);
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

    /**
     * @param  iterable<int, mixed>|string|null  $values
     * @return list<string>
     */
    private function normalizedStringValues(iterable|string|null $values): array
    {
        if (is_string($values)) {
            $trimmed = trim($values);

            return $trimmed !== '' ? [$trimmed] : [];
        }

        if (! is_iterable($values)) {
            return [];
        }

        /** @var list<string> $normalized */
        $normalized = collect($values)
            ->map(static function (mixed $value): ?string {
                if ($value instanceof \BackedEnum) {
                    $value = $value->value;
                }

                if (! is_string($value)) {
                    return null;
                }

                $trimmed = trim($value);

                return $trimmed !== '' ? $trimmed : null;
            })
            ->filter()
            ->values()
            ->all();

        return $normalized;
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

    private function hasSearchableNameColumn(): bool
    {
        return Schema::hasColumn('speakers', 'searchable_name');
    }

    private function hasSpeakerSearchTermsTable(): bool
    {
        return Schema::hasTable('speaker_search_terms');
    }

    /**
     * @param  list<string>  $primaryIds
     * @param  list<string>  $secondaryIds
     * @return list<string>
     */
    private function mergeOrderedIds(array $primaryIds, array $secondaryIds): array
    {
        return collect([...$primaryIds, ...$secondaryIds])
            ->filter(static fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();
    }
}
