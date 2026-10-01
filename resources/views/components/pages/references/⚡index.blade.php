<?php

use App\Enums\DawahShareOutcomeType;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\Reference;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\Auth\IntendedRedirect;
use App\Support\Search\ReferenceSearchService;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
    #[Title('References - ilmu360°')]
    class extends Component
    {
        use WithPagination;

        private const MIN_SEARCH_LENGTH = 3;

        #[Url]
        public ?string $search = null;

        /**
         * @var list<string>
         */
        public array $followingReferenceIds = [];

        public function mount(): void
        {
            $user = auth()->user();

            if (! $user instanceof User) {
                return;
            }

            $this->followingReferenceIds = $user->followingReferences()
                ->pluck((new Reference)->qualifyColumn('id'))
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();
        }

        #[Computed]
        public function references(): LengthAwarePaginatorContract
        {
            $search = $this->normalizedSearch();

            if ($search === null) {
                $paginator = $this->baseReferencesQuery()
                    ->root()
                    ->orderBy('references.title')
                    ->paginate(12)
                    ->withQueryString();
            } elseif (mb_strlen($search) < self::MIN_SEARCH_LENGTH) {
                return $this->emptyPaginator();
            } else {
                $directMatches = $this->directSearch($search);

                $paginator = $directMatches->total() > 0
                    ? $directMatches
                    : $this->fuzzySearch($search);
            }

            $this->attachNextPublicEvents($paginator->items());

            return $paginator;
        }

        public function updatedSearch(): void
        {
            $this->resetPage();
        }

        public function clearSearch(): void
        {
            $this->search = null;
            $this->resetPage();
        }

        public function toggleFollow(string $referenceId): void
        {
            $user = auth()->user();

            if (! $user instanceof User) {
                $this->redirect(
                    IntendedRedirect::loginUrl(route('references.index', absolute: false)),
                    navigate: true,
                );

                return;
            }

            $reference = $this->followableReference($referenceId);

            if ($reference === null) {
                return;
            }

            $referenceId = (string) $reference->getKey();

            if ($user->isFollowing($reference)) {
                $user->unfollow($reference);
                $this->followingReferenceIds = array_values(array_filter(
                    $this->followingReferenceIds,
                    static fn (mixed $id): bool => (string) $id !== $referenceId,
                ));

                return;
            }

            $user->follow($reference);

            if (! in_array($referenceId, $this->followingReferenceIds, true)) {
                $this->followingReferenceIds[] = $referenceId;
            }

            app(ShareTrackingService::class)->recordOutcome(
                type: DawahShareOutcomeType::ReferenceFollow,
                outcomeKey: 'reference_follow:user:'.$user->id.':reference:'.$reference->id,
                subject: $reference,
                actor: $user,
                request: request(),
                metadata: [
                    'reference_id' => $reference->id,
                ],
            );
        }

        private function followableReference(string $referenceId): ?Reference
        {
            if (! Str::isUuid($referenceId)) {
                return null;
            }

            return Reference::query()
                ->active()
                ->whereKey($referenceId)
                ->first();
        }

        private function baseReferencesQuery(bool $includeParts = false): Builder
        {
            $query = Reference::query()
                ->active()
                ->select('references.*')
                ->selectSub($this->nextPublicEventQuery()->select('events.id'), 'next_event_id')
                ->selectSub(Reference::constrainEventReferenceSubtree(Event::query()->active())
                    ->selectRaw('COUNT(DISTINCT events.id)'), 'events_count')
                // Cards only read front/back covers, so skip the gallery
                // collection instead of loading every media row per page.
                ->with([
                    'parentReference.parentReference',
                    'authors.titleAssignments.title.category',
                    'parentReference.authors.titleAssignments.title.category',
                    'parentReference.parentReference.authors.titleAssignments.title.category',
                    'media' => function (MorphMany $relation): void {
                        $relation->whereIn('collection_name', ['front_cover', 'back_cover']);
                    },
                ]);

            if (! $includeParts) {
                $query->root();
            }

            return $query;
        }

        /**
         * Hydrate the next-event card fields for one page of references in a
         * single batched query. The listing only needs these three columns for
         * the visible page, so repeating the same correlated LIMIT 1 subquery
         * three times per row only triples that work — and each repetition
         * evaluates its own "now", which can mix columns from different events
         * at the boundary. Selecting the winning id per row and resolving its
         * columns once keeps every card internally consistent.
         *
         * @param  array<int, Reference>  $references
         */
        private function attachNextPublicEvents(array $references): void
        {
            if ($references === []) {
                return;
            }

            $nextEventIds = collect($references)
                ->map(static fn (Reference $reference): mixed => $reference->getAttribute('next_event_id'))
                ->filter()
                ->map(static fn (mixed $id): string => (string) $id)
                ->unique()
                ->values()
                ->all();

            /** @var EloquentCollection<int, Event> $details */
            $details = $nextEventIds === []
                ? new EloquentCollection
                : $this->nextPublicEventDetails($nextEventIds)->keyBy(static fn (Event $event): string => (string) $event->getKey());

            foreach ($references as $reference) {
                $detail = $details->get((string) $reference->getAttribute('next_event_id'));

                $reference->setAttribute('next_event_slug', $detail?->getAttribute('slug'));
                $reference->setAttribute('next_event_title', $detail?->getAttribute('title'));
                $reference->setAttribute('next_event_starts_at', $detail?->getAttribute('next_event_starts_at'));
                $reference->offsetUnset('next_event_id');
            }
        }

        /**
         * Resolve the card columns for already-selected next events. The id
         * already identifies the earliest future (event, occurrence) pair per
         * reference, so the event's minimum future occurrence start is exactly
         * that pair's start — no tie-break needed for the timestamp itself.
         *
         * @param  list<string>  $eventIds
         * @return EloquentCollection<int, Event>
         */
        private function nextPublicEventDetails(array $eventIds): EloquentCollection
        {
            $occurrencesTable = config('events.database.tables.event_occurrences', 'event_occurrences');

            return Event::query()
                ->select('events.id', 'events.slug', 'events.title')
                ->selectRaw('min("'.$occurrencesTable.'"."starts_at") as next_event_starts_at')
                ->join("{$occurrencesTable}", "{$occurrencesTable}.event_id", '=', 'events.id')
                ->whereIn('events.id', $eventIds)
                ->where("{$occurrencesTable}.starts_at", '>=', now())
                ->groupBy('events.id', 'events.slug', 'events.title')
                ->get();
        }

        /**
         * @return Builder<Event>
         */
        private function nextPublicEventQuery(): Builder
        {
            $occurrencesTable = config('events.database.tables.event_occurrences', 'event_occurrences');

            return Reference::constrainEventReferenceSubtree(Event::query())
                ->join("{$occurrencesTable} as next_event_occurrences", 'next_event_occurrences.event_id', '=', 'events.id')
                ->where('next_event_occurrences.starts_at', '>=', now())
                ->whereNotNull('events.published_at')
                ->whereIn('events.status', Event::PUBLIC_STATUSES)
                ->where('events.visibility', EventVisibility::Public->value)
                ->orderBy('next_event_occurrences.starts_at')
                ->orderBy('events.id')
                ->limit(1);
        }

        private function directSearch(string $search): LengthAwarePaginatorContract
        {
            $matchingIds = $this->filterSearchIdsToCurrentScope(
                $this->referenceSearchService()->publicSearchIds($search),
            );

            if ($matchingIds === []) {
                return $this->emptyPaginator();
            }

            return $this->orderedIdPaginator($matchingIds);
        }

        private function fuzzySearch(string $search): LengthAwarePaginatorContract
        {
            $orderedIds = $this->filterSearchIdsToCurrentScope(
                $this->referenceSearchService()->publicFuzzySearchIds($search),
            );

            if ($orderedIds === []) {
                return $this->emptyPaginator();
            }

            return $this->orderedIdPaginator($orderedIds);
        }

        /**
         * @param  list<string>  $orderedIds
         */
        private function orderedIdPaginator(array $orderedIds): LengthAwarePaginatorContract
        {
            $currentPage = max(1, (int) $this->getPage());
            $perPage = 12;
            $paginationMeta = $this->paginationMeta();
            $paginatedIds = array_slice($orderedIds, ($currentPage - 1) * $perPage, $perPage);

            if ($paginatedIds === []) {
                return new LengthAwarePaginator(collect(), count($orderedIds), $perPage, $currentPage, $paginationMeta);
            }

            $references = $this->baseReferencesQuery(includeParts: true)
                ->whereIn('references.id', $paginatedIds)
                ->get()
                ->sortBy(static function (Reference $reference) use ($paginatedIds): int {
                    $position = array_search((string) $reference->id, $paginatedIds, true);

                    return is_int($position) ? $position : PHP_INT_MAX;
                })
                ->values();

            return new LengthAwarePaginator($references, count($orderedIds), $perPage, $currentPage, $paginationMeta);
        }

        /**
         * @param  list<string>  $orderedIds
         * @return list<string>
         */
        private function filterSearchIdsToCurrentScope(array $orderedIds): array
        {
            if ($orderedIds === []) {
                return [];
            }

            // Lean scope check: same active visibility as the card query
            // but without the events_count subselect and media eager load.
            $scopedIds = Reference::query()
                ->active()
                ->whereIn('references.id', $orderedIds)
                ->pluck('references.id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            return collect($scopedIds)
                ->sortBy(static function (string $id) use ($orderedIds): int {
                    $position = array_search($id, $orderedIds, true);

                    return is_int($position) ? $position : PHP_INT_MAX;
                })
                ->values()
                ->all();
        }

        private function emptyPaginator(): LengthAwarePaginatorContract
        {
            return new LengthAwarePaginator(collect(), 0, 12, max(1, (int) $this->getPage()), $this->paginationMeta());
        }

        private function normalizedSearch(): ?string
        {
            if (! is_string($this->search)) {
                return null;
            }

            $search = trim($this->search);

            return $search === '' ? null : $search;
        }

        /**
         * @return array{path: string, query: array<string, mixed>}
         */
        private function paginationMeta(): array
        {
            return [
                'path' => request()->url(),
                'query' => request()->query(),
            ];
        }

        private function referenceSearchService(): ReferenceSearchService
        {
            return app(ReferenceSearchService::class);
        }
    };
?>

@section('title', __('Reference Directory') . ' - ' . config('app.name'))
@section('meta_description', __('Browse books, articles, videos, and source references connected to public knowledge events.'))
@section('og_url', route('references.index'))
@section('og_image', asset('images/references/rujukan-hero-background-v1.png'))
@section('og_image_alt', __('Reference directory'))
@section('og_image_width', '1024')
@section('og_image_height', '1024')

<div data-art-direction="living-majlis" class="living-majlis-field relative min-h-screen overflow-x-clip text-slate-800">
    <div class="relative isolate overflow-hidden border-b border-emerald-900/[0.06]">
        <div data-material="hero-field" class="absolute inset-0 overflow-hidden bg-[#f7f3e8]">
            <img
                src="{{ asset('images/references/rujukan-hero-background-v1.png') }}"
                alt=""
                aria-hidden="true"
                class="absolute inset-0 h-full w-full object-cover object-[35%_center] sm:object-[42%_center] lg:object-center"
                width="1916"
                height="821"
                loading="eager"
                decoding="async"
            >
            <div
                class="absolute inset-0"
                style="background: linear-gradient(90deg, rgba(255,253,248,.96) 0%, rgba(255,253,248,.90) 26%, rgba(255,253,248,.68) 45%, rgba(255,253,248,.22) 62%, transparent 72%);"
            ></div>
        </div>

        <div class="relative mx-auto max-w-7xl px-5 pt-48 pb-14 sm:px-6 sm:pt-52 sm:pb-20 lg:px-8 lg:pt-52 lg:pb-16">
            <h1 class="max-w-2xl text-balance font-heading text-4xl font-bold leading-[1.06] tracking-[-0.035em] text-emerald-950 sm:text-5xl lg:text-6xl">
                {{ __('Discover sources of') }} <br class="hidden md:block" />
                <span class="relative inline-block text-emerald-700">
                    {{ __('knowledge you can trust') }}
                    <svg class="absolute -bottom-2 left-0 h-3.5 w-full text-amber-500/80" viewBox="0 0 320 18" preserveAspectRatio="none" aria-hidden="true">
                        <path d="M4 13C79 5 218 4 316 10" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round" />
                    </svg>
                </span>
            </h1>
            <p class="mt-6 max-w-xl text-balance text-base leading-7 text-slate-600 sm:mt-7 sm:text-lg">
                {{ __('Terokai kitab, buku, artikel dan bahan rujukan pilihan untuk menyokong perjalanan ilmu anda.') }}
            </p>

            <div class="mt-9 max-w-xl">
                <x-ui.search-bar
                    input-id="reference-search"
                    model="search"
                    :value="$search"
                    :placeholder="__('Search references...')"
                    :label="__('Search references')"
                    :debounce="150"
                    aria-controls="reference-results"
                />
            </div>
        </div>
    </div>

    <div class="mx-auto max-w-7xl px-5 pt-10 pb-16 sm:px-6 lg:px-8 lg:pt-12 lg:pb-20">
        @island(name: 'reference-results', always: true)
            @php
                $references = $this->references;
                $search = $this->search;
                $referenceLoadingTarget = 'search,clearSearch,gotoPage,setPage,nextPage,previousPage';
            @endphp

            <div
                id="reference-results"
                class="min-h-[32rem]"
                wire:transition="reference-results"
                wire:loading.class="opacity-60"
                wire:loading.attr="aria-busy"
                wire:target="{{ $referenceLoadingTarget }}"
                role="region"
                aria-label="{{ __('Reference list') }}"
            >
                <div wire:loading.delay.short wire:target="{{ $referenceLoadingTarget }}" role="status" aria-live="polite">
                    <span class="sr-only">{{ __('Loading reference list…') }}</span>
                    <x-ui.skeleton.institution-card-grid :items="12" columns="sm:grid-cols-2 lg:grid-cols-4" />
                </div>

                <div wire:loading.remove wire:target="{{ $referenceLoadingTarget }}">
            @if($references->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-200 bg-slate-50/50 py-24 text-center">
                    <div class="mb-6 inline-flex h-20 w-20 items-center justify-center rounded-full bg-white text-slate-300 shadow-sm">
                        <svg class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900">
                        @if(filled($search) && mb_strlen(trim((string) $search)) < 3)
                            {{ __('Continue typing to search') }}
                        @else
                            {{ __('No references found') }}
                        @endif
                    </h3>
                    <p class="mx-auto mt-2 max-w-md text-slate-500">
                        @if(filled($search) && mb_strlen(trim((string) $search)) < 3)
                            {{ __('Type at least 3 characters to search.') }}
                        @else
                            {{ __('We couldn\'t find any references matching your search.') }}
                        @endif
                    </p>
                    <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                        <button type="button" wire:click="clearSearch" class="font-semibold text-emerald-600 hover:text-emerald-700">
                            {{ __('Clear Search') }} &rarr;
                        </button>
                    </div>
                </div>
            @else
                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach($references as $reference)
                        @php
                            $coverUrl = $reference->getFirstMediaUrl('front_cover', 'thumb') ?: $reference->getFirstMediaUrl('back_cover', 'thumb');
                            $referenceType = \App\Enums\ReferenceType::tryFrom((string) $reference->type);
                            $typeLabel = $referenceType?->getLabel() ?? (filled($reference->type) ? \Illuminate\Support\Str::headline((string) $reference->type) : __('Reference'));
                            $metaParts = array_values(array_filter([
                                $reference->effectiveAuthorNames(),
                                $reference->publisher,
                                $reference->year,
                            ], fn (mixed $value): bool => filled($value)));
                            $nextEventStartsAt = filled($reference->next_event_starts_at)
                                ? CarbonImmutable::parse((string) $reference->next_event_starts_at, 'UTC')
                                : null;
                            $isFollowing = in_array((string) $reference->getKey(), $followingReferenceIds, true);
                        @endphp

                        <article
                            wire:key="reference-{{ $reference->id }}"
                            class="living-majlis-card group relative flex flex-col overflow-hidden rounded-[1.5rem] border border-emerald-200 transition-[border-color,background-color,box-shadow,transform] duration-300 hover:-translate-y-1.5 hover:border-amber-200 hover:bg-amber-100 hover:shadow-[0_22px_50px_-28px_rgba(217,165,20,0.40)]"
                        >
                            <a
                                href="{{ route('references.show', $reference) }}"
                                wire:navigate
                                class="flex flex-1 flex-col after:absolute after:inset-0"
                            >
                                <div class="relative flex aspect-4/5 items-center justify-center overflow-hidden bg-linear-to-br from-slate-50 to-emerald-50">
                                    @if($coverUrl)
                                        <img src="{{ $coverUrl }}" alt="{{ $reference->displayTitle() }}" class="h-full w-full object-cover transition-transform duration-700 group-hover:scale-105" width="200" height="280" loading="lazy">
                                        <div class="absolute inset-0 bg-linear-to-t from-slate-900/55 via-slate-900/10 to-transparent"></div>
                                    @else
                                        <flux:icon.book-open class="size-20 text-emerald-200 transition-transform duration-700 group-hover:scale-110" />
                                    @endif

                                    <span class="absolute left-4 top-4 rounded-full bg-white/95 px-3 py-1 text-xs font-bold text-emerald-700 shadow-sm ring-1 ring-emerald-100">
                                        {{ $typeLabel }}
                                    </span>
                                </div>

                                <div class="flex flex-1 flex-col p-6 pb-0">
                                    <h3 class="mb-2 line-clamp-2 font-heading text-lg font-bold leading-tight text-slate-900 transition-colors group-hover:text-emerald-700">
                                        {{ $reference->displayTitle() }}
                                    </h3>

                                    @if($metaParts !== [])
                                        <p class="mb-4 line-clamp-3 text-sm font-medium leading-6 text-slate-600">
                                            {{ implode(' / ', $metaParts) }}
                                        </p>
                                    @else
                                        <p class="mb-4 line-clamp-3 text-sm font-medium leading-6 text-slate-500">
                                            {{ __('Reference details will be updated soon.') }}
                                        </p>
                                    @endif
                                </div>
                            </a>
                            @if($nextEventStartsAt instanceof CarbonImmutable && filled($reference->next_event_slug) && filled($reference->next_event_title))
                                <a
                                    data-next-event
                                    href="{{ route('events.show', ['event' => $reference->next_event_slug]) }}"
                                    wire:navigate
                                    class="group/next relative z-10 mx-6 mt-4 block min-w-0 border-t border-slate-100 pb-4 pt-4 transition-colors duration-200 group-hover:border-amber-200 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/15"
                                >
                                    <span class="min-w-0">
                                        <span class="block text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">{{ __('Next event') }}</span>
                                        <span class="mt-1 block truncate text-[11px] font-semibold text-slate-700 transition-colors group-hover/next:font-bold group-hover/next:text-emerald-800 sm:text-xs">
                                            {{ UserDateTimeFormatter::translatedFormat($nextEventStartsAt, 'j M') }}
                                            <span class="text-slate-300" aria-hidden="true">·</span>
                                            {{ $reference->next_event_title }}
                                        </span>
                                    </span>
                                </a>
                            @endif
                            <div class="mt-auto flex items-center justify-between gap-3 border-t border-slate-100 px-6 pb-6 pt-5 group-hover:border-amber-200">
                                <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                    <flux:icon.calendar class="size-3.5 text-emerald-500" />
                                    {{ $reference->events_count }} {{ __('Events') }}
                                </span>
                                <button
                                    type="button"
                                    wire:click.stop.prevent="toggleFollow('{{ $reference->id }}')"
                                    wire:loading.attr="disabled"
                                    data-follow-icon="reference"
                                    data-follow-state="{{ $isFollowing ? 'following' : 'not-following' }}"
                                    aria-label="{{ $isFollowing ? __('Nyahikut') : __('Ikuti') }}"
                                    aria-pressed="{{ $isFollowing ? 'true' : 'false' }}"
                                    class="relative z-10 grid h-9 w-9 shrink-0 place-items-center rounded-xl border transition-colors duration-200 disabled:cursor-wait disabled:opacity-60 {{ $isFollowing ? 'border-emerald-200 bg-emerald-50 text-emerald-700 group-hover:border-emerald-300 group-hover:bg-emerald-100' : 'border-slate-200 bg-white text-slate-400 group-hover:border-emerald-200 group-hover:text-[#087f59] hover:border-emerald-200 hover:bg-emerald-100 hover:text-[#087f59] hover:shadow-sm' }}"
                                >
                                    <flux:icon.bookmark class="size-4" :variant="$isFollowing ? 'solid' : 'outline'" />
                                </button>
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="mt-16">
                    {{ $references->links('vendor.livewire.directory-pagination') }}
                </div>

            @endif
                </div>
            </div>
        @endisland
    </div>
</div>
