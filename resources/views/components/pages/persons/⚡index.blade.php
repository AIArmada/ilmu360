<?php

use AIArmada\Persons\Enums\AssignmentStatus;
use AIArmada\Persons\Enums\Gender;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Person;
use App\Support\Search\PersonSearchService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
    #[Title('Persons - ilmu360°')]
    class extends Component
    {
        use WithPagination;

        private const MIN_SEARCH_LENGTH = 3;

        #[Url]
        public ?string $search = null;

        #[Url]
        public ?string $sort = null;

        #[Url]
        public ?string $title_id = null;

        #[Url]
        public ?string $language_id = null;

        #[Url]
        public ?string $state_id = null;

        private function applySort(Builder $query): Builder
        {
            return match ($this->sort) {
                'name' => $query->orderBy('persons.name'),
                default => $query->publicDirectoryOrder(),
            };
        }

        #[Computed]
        public function persons(): LengthAwarePaginatorContract
        {
            $search = $this->normalizedSearch();

            if ($search === null) {
                return $this->attachUpcomingEventCountsToPaginator(
                    $this->applySort($this->basePersonsQuery())->paginate(12)->appends($this->directoryQueryString()),
                );
            }

            if (mb_strlen($search) < self::MIN_SEARCH_LENGTH) {
                return $this->emptyPaginator();
            }

            $directMatches = $this->directSearch($search);

            if ($directMatches->total() > 0) {
                return $directMatches;
            }

            return $this->fuzzySearch($search);
        }

        public function updatedSearch(): void
        {
            $this->resetPage();
        }

        public function updatedSort(): void
        {
            $this->resetPage();
        }

        public function updatedTitleId(): void
        {
            $this->resetPage();
        }

        public function updatedLanguageId(): void
        {
            $this->resetPage();
        }

        public function updatedStateId(): void
        {
            $this->resetPage();
        }

        public function clearFilters(): void
        {
            $this->title_id = null;
            $this->language_id = null;
            $this->state_id = null;
            $this->resetPage();
        }

        public function clearSearch(): void
        {
            $this->search = null;
            $this->resetPage();
        }

        private function basePersonsQuery(): Builder
        {
            return $this->applyDirectoryFilters(
                Person::query()
                    ->speakers()
                    ->whereIn('status', ['verified', 'pending']),
            )->with([
                'media' => function (MorphMany $relation): void {
                    $relation->where('collection_name', 'profile');
                },
                'addresses.state',
            ]);
        }

        private function applyDirectoryFilters(Builder $query): Builder
        {
            $titleId = $this->normalizedFilterId($this->title_id);
            $languageId = $this->normalizedFilterId($this->language_id);
            $stateId = $this->normalizedFilterId($this->state_id);

            return $query
                ->when($titleId !== null, function (Builder $query) use ($titleId): void {
                    $query->whereHas('titleAssignments', function (Builder $titleQuery) use ($titleId): void {
                        $titleQuery
                            ->where('title_id', $titleId)
                            ->where('status', AssignmentStatus::Active);
                    });
                })
                ->when($languageId !== null, function (Builder $query) use ($languageId): void {
                    $query->whereHas('languages', function (Builder $languageQuery) use ($languageId): void {
                        $languageQuery->where('languages.id', $languageId);
                    });
                })
                ->when($stateId !== null, function (Builder $query) use ($stateId): void {
                    $query->whereHas('addresses', function (Builder $addressQuery) use ($stateId): void {
                        $addressQuery->where('state_id', $stateId);
                    });
                });
        }

        private function normalizedFilterId(?string $value): ?string
        {
            if (! is_string($value)) {
                return null;
            }

            $value = trim($value);

            return Str::isUuid($value) ? $value : null;
        }

        private function directSearch(string $search): LengthAwarePaginatorContract
        {
            $matchingIds = $this->personSearchService()->publicSearchIds($search);

            if ($matchingIds === []) {
                return $this->emptyPaginator();
            }

            if ($this->sort === 'name') {
                return $this->attachUpcomingEventCountsToPaginator(
                    $this->applySort($this->basePersonsQuery()->whereIn('persons.id', $matchingIds))
                        ->paginate(12)
                        ->withQueryString(),
                );
            }

            return $this->orderedIdPaginator($matchingIds);
        }

        private function fuzzySearch(string $search): LengthAwarePaginatorContract
        {
            return $this->orderedIdPaginator(
                $this->personSearchService()->publicFuzzySearchIds($search),
            );
        }

        /**
         * @param  list<string>  $orderedIds
         */
        private function orderedIdPaginator(array $orderedIds): LengthAwarePaginatorContract
        {
            $orderedIds = $this->scopeOrderedIds($orderedIds);

            if ($orderedIds === []) {
                return $this->emptyPaginator();
            }

            if ($this->sort === 'name') {
                return $this->attachUpcomingEventCountsToPaginator(
                    $this->applySort($this->basePersonsQuery()->whereIn('persons.id', $orderedIds))
                        ->paginate(12)
                        ->withQueryString(),
                );
            }

            $currentPage = max(1, (int) $this->getPage());
            $perPage = 12;
            $paginationMeta = $this->paginationMeta();
            $paginatedIds = array_slice($orderedIds, ($currentPage - 1) * $perPage, $perPage);

            if ($paginatedIds === []) {
                return new LengthAwarePaginator(collect(), count($orderedIds), $perPage, $currentPage, $paginationMeta);
            }

            $persons = $this->basePersonsQuery()
                ->whereIn('persons.id', $paginatedIds)
                ->get()
                ->sortBy(static function (Person $person) use ($paginatedIds): int {
                    $position = array_search((string) $person->id, $paginatedIds, true);

                    return is_int($position) ? $position : PHP_INT_MAX;
                })
                ->values();

            $this->attachUpcomingEventCountsToPersons($persons);

            return new LengthAwarePaginator($persons, count($orderedIds), $perPage, $currentPage, $paginationMeta);
        }

        /**
         * @param  list<string>  $orderedIds
         * @return list<string>
         */
        private function scopeOrderedIds(array $orderedIds): array
        {
            if ($orderedIds === []) {
                return [];
            }

            $scopedIds = $this->basePersonsQuery()
                ->whereIn('persons.id', $orderedIds)
                ->pluck('persons.id')
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

        private function attachUpcomingEventCountsToPaginator(LengthAwarePaginator $paginator): LengthAwarePaginator
        {
            $this->attachUpcomingEventCountsToPersons($paginator->getCollection());

            return $paginator;
        }

        /**
         * @param  Collection<int, Person>  $persons
         */
        private function attachUpcomingEventCountsToPersons(Collection $persons): void
        {
            $personIds = $persons
                ->map(static fn (Person $person): string => (string) $person->getKey())
                ->filter(static fn (string $id): bool => $id !== '')
                ->values();

            if ($personIds->isEmpty()) {
                return;
            }

            $eventsTable = (new Event)->getTable();
            $eventInvolvementsTable = (new EventKeyPerson)->getTable();
            $occurrencesTable = config('events.database.tables.event_occurrences', 'event_occurrences');
            $now = now();
            $upcomingEvents = DB::table($occurrencesTable)
                ->select('event_id')
                ->groupBy('event_id')
                // MIN(starts_at) is the same earliest-occurrence value used by
                // EventBuilder, including the past-plus-future case.
                ->havingRaw('min(starts_at) >= ?', [$now]);

            $counts = DB::table("{$eventInvolvementsTable} as event_involvements")
                ->join("{$eventsTable} as events", 'events.id', '=', 'event_involvements.event_id')
                ->joinSub($upcomingEvents, 'upcoming_events', 'upcoming_events.event_id', '=', 'events.id')
                ->where('event_involvements.involveable_type', (new Person)->getMorphClass())
                ->whereIn('event_involvements.involveable_id', $personIds->all())
                ->whereIn('events.status', Event::PUBLIC_STATUSES)
                ->where('events.visibility', EventVisibility::Public)
                ->whereNotNull('events.published_at')
                ->whereExists(function ($occurrenceQuery) use ($occurrencesTable): void {
                    $occurrenceQuery
                        ->selectRaw('1')
                        ->from("{$occurrencesTable} as occurrences")
                        ->whereColumn('occurrences.event_id', 'events.id');
                })
                ->selectRaw('event_involvements.involveable_id, count(*) as events_count')
                ->groupBy('event_involvements.involveable_id')
                ->pluck('events_count', 'event_involvements.involveable_id');

            foreach ($persons as $person) {
                $person->setAttribute('events_count', (int) ($counts->get((string) $person->getKey()) ?? 0));
            }
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
         * @return array<string, string>
         */
        private function directoryQueryString(): array
        {
            return collect([
                'search' => $this->normalizedSearch(),
                'sort' => $this->sort,
                'title_id' => $this->normalizedFilterId($this->title_id),
                'language_id' => $this->normalizedFilterId($this->language_id),
                'state_id' => $this->normalizedFilterId($this->state_id),
            ])
                ->filter(static fn (mixed $value): bool => filled($value))
                ->map(static fn (mixed $value): string => (string) $value)
                ->all();
        }

        /**
         * @return array{path: string, query: array<string, mixed>}
         */
        private function paginationMeta(): array
        {
            return [
                'path' => request()->url(),
                'query' => array_merge(request()->query(), $this->directoryQueryString()),
            ];
        }

        private function personSearchService(): PersonSearchService
        {
            return app(PersonSearchService::class);
        }
    };
?>

@section('title', __('Islamic speaker directory') . ' - ' . config('app.name'))
@section('meta_description', __('Find trusted speaker profiles, upcoming Islamic learning events, and talks across Malaysia.'))
@section('og_url', route('persons.index'))
@section('og_image', asset('images/placeholders/person.png'))
@section('og_image_alt', __('Islamic speaker directory'))
@section('og_image_width', '1024')
@section('og_image_height', '1024')

<div data-art-direction="living-majlis" class="living-majlis-field relative min-h-screen overflow-x-clip text-slate-800">
    <!-- Hero Section -->
    <div class="relative overflow-hidden border-b border-emerald-900/[0.06]">
        <!-- Background layers -->
        <div data-material="hero-field" class="absolute inset-0 bg-[radial-gradient(ellipse_at_18%_28%,rgba(5,101,82,0.10)_0%,transparent_42%),radial-gradient(ellipse_at_82%_18%,rgba(217,119,6,0.06)_0%,transparent_36%),linear-gradient(178deg,#fafaf7_0%,#f4f1e8_54%,#e7eee8_100%)]"></div>

        <div class="relative mx-auto max-w-4xl px-5 py-14 sm:px-6 sm:py-20 lg:px-8 lg:py-24">
            <div class="max-w-3xl">
                    <h1 class="mt-6 max-w-3xl font-heading text-4xl font-bold leading-[1.06] tracking-[-0.035em] text-emerald-950 sm:text-5xl lg:text-6xl">
                        {{ __('Meet speakers who are') }}
                        <span class="relative inline-block text-emerald-700">
                            {{ __('trusted and knowledgeable') }}
                            <svg class="absolute -bottom-2 left-0 h-3.5 w-full text-amber-500/80" viewBox="0 0 320 18" preserveAspectRatio="none" aria-hidden="true">
                                <path d="M4 13C79 5 218 4 316 10" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round" />
                            </svg>
                        </span>
                    </h1>

                    <p class="mt-6 max-w-xl text-base leading-7 text-slate-600 sm:mt-7 sm:text-lg">
                        {{ __('Find ustaz, ustazah, and preachers across Malaysia. Learn about their work, see upcoming majlis, and continue your learning journey.') }}
                    </p>

                    <!-- Search Box - refined pill -->
                    <div class="mt-9 max-w-xl">
                        <div data-material="translucent-control" class="living-majlis-veil group relative rounded-[1.5rem] p-1.5 transition-all duration-300 focus-within:scale-[1.01] focus-within:border-emerald-300 focus-within:ring-4 focus-within:ring-emerald-600/10 focus-within:shadow-[0_28px_70px_-30px_rgba(6,78,59,0.50)]">
                            <label for="person-search" class="sr-only">{{ __('Search speakers') }}</label>
                            <div class="flex items-center gap-3">
                                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-emerald-50 text-emerald-700 transition-colors duration-300 group-focus-within:bg-emerald-100">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m1.35-5.4a6.75 6.75 0 1 1-13.5 0 6.75 6.75 0 0 1 13.5 0Z" />
                                    </svg>
                                </span>

                                <input
                                    type="search"
                                    id="person-search"
                                    aria-controls="person-results"
                                    wire:model.live.debounce.150ms="search"
                                    wire:keydown.escape="clearSearch"
                                    placeholder="{{ __('Search speaker name…') }}"
                                    autocomplete="off"
                                    class="person-search-input h-12 min-w-0 flex-1 appearance-none border-0 bg-transparent px-0 text-base font-medium text-slate-900 placeholder:text-slate-400 focus:border-transparent focus:outline-none focus:ring-0 focus-visible:outline-none"
                                >

                                @if(filled($search))
                                    <button
                                        type="button"
                                        wire:click="clearSearch"
                                        wire:loading.attr="disabled"
                                        wire:target="clearSearch"
                                        aria-label="{{ __('Clear speaker search') }}"
                                        class="grid h-10 w-10 shrink-0 place-items-center rounded-full border border-slate-200 bg-white text-slate-400 shadow-sm transition-all duration-200 hover:border-rose-200 hover:bg-rose-50 hover:text-rose-600 focus:outline-none focus:ring-4 focus:ring-rose-500/10"
                                    >
                                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l8 8M14 6l-8 8" />
                                        </svg>
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>


                </div>

            </div>
        </div>

    <!-- Main Content -->
    <div class="mx-auto max-w-7xl px-5 py-10 sm:px-6 lg:px-8 lg:py-12">
        @island(name: 'person-results', always: true)
            @php
                $persons = $this->persons;
                $search = $this->search;
                $personLoadingTarget = 'search,sort,title_id,language_id,state_id,clearSearch,clearFilters,gotoPage,setPage,nextPage,previousPage';
                $submitPersonUrl = route('contributions.submit-person');
                $personTotal = $persons->total();
                $activeFilterCount = collect([$this->title_id, $this->language_id, $this->state_id])
                    ->filter(static fn (mixed $value): bool => filled($value))
                    ->count();
            @endphp

            <div
                id="person-results"
                class="min-h-[32rem]"
                wire:transition="person-results"
                wire:loading.class="opacity-60"
                wire:loading.attr="aria-busy"
                wire:target="{{ $personLoadingTarget }}"
                role="region"
                aria-label="{{ __('Speaker list') }}"
            >
                <!-- Loading Skeleton -->
                <div wire:loading.delay.short wire:target="{{ $personLoadingTarget }}" role="status" aria-live="polite">
                    <span class="sr-only">{{ __('Loading speaker list…') }}</span>
                    <x-ui.skeleton.person-card-grid />
                </div>

                <div wire:loading.remove wire:target="{{ $personLoadingTarget }}">
            <!-- Empty State -->
            @if($persons->isEmpty())
                <div class="flex min-h-[26rem] items-center justify-center">
                    <div class="flex max-w-md flex-col items-center text-center">
                        <div class="grid h-22 w-22 place-items-center rounded-[1.5rem] bg-white text-emerald-300 shadow-lg shadow-emerald-900/[0.06] ring-1 ring-emerald-100">
                            <svg class="h-9 w-9" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m1.35-5.4a6.75 6.75 0 1 1-13.5 0 6.75 6.75 0 0 1 13.5 0Z" />
                            </svg>
                        </div>

                        <h2 class="mt-6 font-heading text-2xl font-bold text-emerald-950">
                            @if(filled($search) && mb_strlen(trim((string) $search)) < 3)
                                {{ __('Continue typing to search') }}
                            @else
                                {{ __('No speakers found') }}
                            @endif
                        </h2>
                        <p class="mt-3 max-w-sm text-sm leading-6 text-slate-500">
                            @if(filled($search) && mb_strlen(trim((string) $search)) < 3)
                                {{ __('Type at least 3 characters to search.') }}
                            @elseif(filled($search))
                                {{ __('No profile matches “:search”. Try a different spelling or the full name.', ['search' => $search]) }}
                            @elseif($activeFilterCount > 0)
                                {{ __('No speakers match these filters. Try changing or clearing them.') }}
                            @else
                                {{ __('The speaker directory is empty right now. Please check back later.') }}
                            @endif
                        </p>

                        <div class="mt-8 flex flex-col items-center gap-3 sm:flex-row">
                            @if(filled($search))
                                <button
                                    type="button"
                                    wire:click="clearSearch"
                                    wire:loading.attr="disabled"
                                    class="inline-flex h-11 items-center justify-center gap-2.5 rounded-xl bg-emerald-800 px-5 text-sm font-bold text-white shadow-[0_14px_28px_-18px_rgba(6,78,59,0.70)] transition-all duration-200 hover:-translate-y-0.5 hover:bg-emerald-700 hover:shadow-[0_20px_36px_-18px_rgba(6,78,59,0.75)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/15 disabled:cursor-wait disabled:opacity-60"
                                >
                                    {{ __('View all speakers') }}
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5 5 5-5 5" />
                                    </svg>
                                </button>
                            @endif

                            @if($activeFilterCount > 0)
                                <button
                                    type="button"
                                    wire:click="clearFilters"
                                    wire:loading.attr="disabled"
                                    class="inline-flex h-11 items-center justify-center gap-2.5 rounded-xl border border-emerald-200 bg-white px-5 text-sm font-bold text-emerald-700 transition-all duration-200 hover:-translate-y-0.5 hover:border-emerald-300 hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/10 disabled:cursor-wait disabled:opacity-60"
                                >
                                    {{ __('Clear filters') }}
                                </button>
                            @endif

                            <a
                                href="{{ $submitPersonUrl }}"
                                wire:navigate
                                class="inline-flex h-11 items-center justify-center gap-2.5 rounded-xl border-2 border-emerald-200 bg-white px-5 text-sm font-bold text-emerald-700 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-emerald-300 hover:bg-emerald-50 hover:shadow-md"
                            >
                                {{ __('Suggest a speaker') }}
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14" />
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>
            @else
                <!-- Results Header -->
                <div class="mb-8 flex flex-col items-stretch gap-5 border-b border-slate-200/70 pb-6 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="font-heading text-2xl font-bold tracking-tight text-emerald-950 sm:text-3xl">
                            @if(filled($search))
                                {{ __('Search results for “:search”', ['search' => $search]) }}
                            @else
                                {{ __('Speaker directory') }}
                            @endif
                        </h2>
                        <p id="person-results-summary" class="mt-2 text-sm text-slate-500" aria-live="polite">
                            {{ trans_choice(':count speaker found|:count speakers found', $personTotal, ['count' => number_format($personTotal)]) }}
                            @if($activeFilterCount > 0)
                                <span class="text-slate-400">·</span>
                                {{ trans_choice(':count active filter|:count active filters', $activeFilterCount, ['count' => $activeFilterCount]) }}
                            @endif
                        </p>
                    </div>

                    @unless(filled($search))
                        <div class="flex w-fit shrink-0 flex-col gap-1.5 self-end sm:self-auto" role="group" aria-label="{{ __('Sort directory') }}">
                            <span class="px-1 text-[11px] font-bold uppercase tracking-[0.16em] text-slate-400">{{ __('Sort') }}</span>
                            <div data-material="translucent-control" class="living-majlis-veil flex items-center gap-1 rounded-xl p-0.5">
                                <button
                                    type="button"
                                    wire:click="$set('sort', null)"
                                    aria-pressed="{{ $sort === null ? 'true' : 'false' }}"
                                    class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-all duration-150 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/10 {{ $sort === null ? 'bg-emerald-800 text-white' : 'text-slate-500 hover:text-slate-800' }}"
                                >
                                    {{ __('Random') }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="$set('sort', 'name')"
                                    aria-pressed="{{ $sort === 'name' ? 'true' : 'false' }}"
                                    class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-all duration-150 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/10 {{ $sort === 'name' ? 'bg-emerald-800 text-white' : 'text-slate-500 hover:text-slate-800' }}"
                                >
                                    {{ __('Name A–Z') }}
                                </button>
                            </div>
                        </div>
                    @endunless
                </div>

                <!-- Person Grid -->
                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach($persons as $person)
                        <a
                            href="{{ route('persons.show', $person) }}"
                            wire:key="person-directory-{{ $person->id }}"
                            wire:navigate
                            data-material="opaque-card"
                            class="living-majlis-card group relative flex min-h-[10rem] gap-0 overflow-hidden rounded-[1.5rem] transition-[border-color,box-shadow,transform] duration-300 hover:-translate-y-1.5 hover:border-emerald-300/80 hover:shadow-[0_22px_50px_-28px_rgba(6,78,59,0.40)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/15 focus-visible:ring-offset-2 sm:block sm:min-h-0"
                        >
                            <!-- Image area -->
                            <div class="relative w-28 shrink-0 overflow-hidden bg-gradient-to-br from-[#faf5e8] via-[#eff5f1] to-[#dce9e2] sm:w-full sm:aspect-[3/4]">
                                <!-- Directory texture keeps the placeholder art grounded. -->
                                <div class="absolute inset-0 opacity-[0.15]" style="background-image: radial-gradient(circle at 1.5px 1.5px, rgba(7,91,72,.14) 1px, transparent 0); background-size: 16px 16px;"></div>
                                @php
                                    $gender = Gender::tryFrom((string) $person->getRawOriginal('gender'));
                                    $placeholderImage = match ($gender) {
                                        Gender::Female => asset('images/placeholders/person-female.png'),
                                        Gender::Male => asset('images/placeholders/person-male.png'),
                                        default => null,
                                    };
                                    $initials = str($person->name)->explode(' ')
                                        ->reject(fn (string $word): bool => in_array(strtolower($word), ['bin', 'binti', 'ibni', 'ibn', 'binte', 'abd', 'abdul', 'abu'], true))
                                        ->take(2)
                                        ->map(fn (string $word): string => str($word)->substr(0, 1)->upper())
                                        ->implode('');
                                    $personState = $person->primaryAddress()?->state?->name;
                                @endphp

                                @if($person->hasMedia('profile'))
                                    <img
                                        src="{{ $person->public_main_url }}"
                                        alt=""
                                        aria-hidden="true"
                                        class="relative h-full w-full object-cover object-top"
                                        width="300"
                                        height="400"
                                        loading="lazy"
                                    >
                                @else
                                    @if($placeholderImage !== null)
                                        <img
                                            src="{{ $placeholderImage }}"
                                            alt=""
                                            aria-hidden="true"
                                            data-placeholder="speaker-image"
                                            data-placeholder-variant="{{ $gender->value }}"
                                            class="relative h-full w-full object-cover object-top"
                                            width="300"
                                            height="400"
                                            loading="lazy"
                                        >
                                    @else
                                        <div
                                            data-placeholder="speaker-image"
                                            data-placeholder-variant="neutral"
                                            class="relative flex h-full w-full items-center justify-center"
                                            aria-hidden="true"
                                        >
                                            <span class="font-heading text-[clamp(1.5rem,4vw,2.75rem)] font-bold tracking-tight text-emerald-800/30">{{ $initials }}</span>
                                        </div>
                                    @endif
                                @endif

                                <!-- Gradient fade at bottom -->
                                <div class="absolute inset-x-0 bottom-0 hidden h-28 bg-gradient-to-t from-emerald-950/80 via-emerald-950/30 to-transparent sm:block"></div>

                                <!-- Status badge -->
                                @if((string) $person->status === 'verified')
                                    <span class="absolute start-2.5 top-2.5 inline-flex items-center gap-1.5 rounded-full border border-white/70 bg-white/92 px-2.5 py-1 text-[10px] font-bold text-emerald-800 shadow-sm backdrop-blur sm:start-3 sm:top-3">
                                        <svg class="h-3.5 w-3.5 text-emerald-700" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.051l-7.5 9.75a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.897 3.896 6.976-9.07a.75.75 0 0 1 1.051-.142Z" clip-rule="evenodd" />
                                        </svg>
                                        {{ __('Disahkan') }}
                                    </span>
                                @elseif((string) $person->status === 'pending')
                                    <span class="absolute start-2.5 top-2.5 inline-flex items-center gap-1.5 rounded-full border border-amber-300/70 bg-amber-50/92 px-2.5 py-1 text-[10px] font-bold text-amber-800 shadow-sm backdrop-blur sm:start-3 sm:top-3">
                                        <svg class="h-3.5 w-3.5 text-amber-600" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M12 2.25a.75.75 0 0 1 .66.4l9 15.75a.75.75 0 0 1-.66 1.125H3a.75.75 0 0 1-.66-1.125l9-15.75a.75.75 0 0 1 .66-.4Zm0 6a.75.75 0 0 1 .75.75v3.75a.75.75 0 0 1-1.5 0V9a.75.75 0 0 1 .75-.75Zm0 7.5a.9.9 0 1 0 0 1.8.9.9 0 0 0 0-1.8Z" clip-rule="evenodd" />
                                        </svg>
                                        {{ __('Belum disahkan') }}
                                    </span>
                                @endif

                                <!-- Arrow affordance -->
                                <div class="absolute inset-x-3 bottom-3 hidden items-center justify-end gap-2 text-white sm:flex">
                                    <svg class="h-4 w-4 transition-transform duration-300 group-hover:translate-x-1.5 motion-reduce:transition-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5 5 5-5 5" />
                                    </svg>
                                </div>
                            </div>

                            <!-- Content area -->
                            <div class="flex min-w-0 flex-1 flex-col p-4 sm:p-5">
                                <h3 class="font-heading text-lg font-bold leading-tight tracking-[-0.02em] text-emerald-950 transition-colors duration-200 group-hover:text-emerald-700">
                                    {{ $person->formatted_name }}
                                </h3>

                                @if(filled($personState))
                                    <p class="mt-2 flex items-center gap-1.5 truncate text-xs font-medium text-slate-500">
                                        <svg class="h-3.5 w-3.5 shrink-0 text-gold-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                                        </svg>
                                        {{ $personState }}
                                    </p>
                                @endif

                                <div class="mt-4 flex items-center gap-2.5 text-xs text-slate-500" aria-label="{{ trans_choice(':count upcoming majlis|:count upcoming majlis', $person->events_count, ['count' => number_format($person->events_count)]) }}">
                                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-xl bg-gold-50 text-gold-600 ring-1 ring-gold-100">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2Z" />
                                        </svg>
                                    </span>
                                    <span>
                                        <strong class="font-bold text-slate-800">{{ number_format($person->events_count) }}</strong>
                                        {{ trans_choice('upcoming majlis|upcoming majlis', $person->events_count) }}
                                    </span>
                                </div>

                                <div class="mt-auto pt-4">
                                    <span class="inline-flex items-center gap-2 text-xs font-bold text-emerald-700 transition-colors duration-200 group-hover:text-emerald-600 sm:text-sm">
                                        {{ __('View profile & majlis') }}
                                        <svg class="h-3.5 w-3.5 transition-transform duration-300 group-hover:translate-x-1 motion-reduce:transition-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5 5 5-5 5" />
                                        </svg>
                                    </span>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>

                <!-- Pagination -->
                @if($persons->hasPages())
                    <div id="person-pagination" class="mt-10 rounded-2xl border border-slate-200/80 bg-white px-5 py-4">
                        {{ $persons->links(data: ['scrollTo' => '#person-results']) }}
                    </div>
                @endif
            @endif

            <!-- Community Contribution CTA -->
            <section class="mt-14 sm:mt-20">
                <div data-material="opaque-cta" class="living-majlis-cta relative overflow-hidden rounded-[1.5rem] border border-emerald-800/15 px-6 py-10 text-white sm:px-8 md:px-10 md:py-12">
                    <!-- One quiet field texture keeps the dark CTA grounded. -->
                    <div class="absolute inset-0 opacity-[0.08]" style="background-image: radial-gradient(circle at 1.5px 1.5px, rgba(255,255,255,.70) 1px, transparent 0); background-size: 22px 22px;"></div>
                    <div class="absolute -bottom-16 -left-16 h-48 w-48 rounded-full bg-emerald-700/[0.15] blur-3xl"></div>

                    <div class="relative flex flex-col gap-8 lg:flex-row lg:items-center lg:justify-between">
                        <div class="max-w-2xl">
                            <h2 class="max-w-xl font-heading text-2xl font-bold leading-snug tracking-tight text-balance sm:text-3xl">
                                {{ __('Know a speaker who is missing?') }}
                            </h2>
                            <p class="mt-4 max-w-2xl text-sm leading-6 text-emerald-100/75 sm:text-base">
                                {{ __('Help the community find more teachers and preachers. Every suggestion is reviewed before it is published.') }}
                            </p>
                        </div>

                        <a
                            href="{{ $submitPersonUrl }}"
                            wire:navigate
                            class="group inline-flex min-h-14 w-full items-center justify-between gap-5 rounded-[1.25rem] bg-white px-5 py-3.5 text-left text-emerald-900 shadow-[0_20px_40px_-20px_rgba(0,25,11,0.55)] transition-all duration-200 hover:-translate-y-0.5 hover:bg-amber-50 hover:shadow-[0_24px_48px_-20px_rgba(0,25,11,0.65)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-gold-400/30 sm:w-auto sm:min-w-[18rem]"
                        >
                            <span class="text-sm font-bold sm:text-base">{{ __('Suggest a speaker') }}</span>
                            <svg class="h-5 w-5 shrink-0 transition-transform duration-300 group-hover:translate-x-1.5 motion-reduce:transition-none" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.167 10h11.666m0 0-4.166-4.167M15.833 10l-4.166 4.167" />
                            </svg>
                        </a>
                    </div>
                </div>
            </section>
                </div>
            </div>
        @endisland
    </div>

    <x-filament-actions::modals />
</div>
