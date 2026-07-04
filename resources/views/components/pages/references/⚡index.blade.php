<?php

use App\Models\Reference;
use App\Support\Search\ReferenceSearchService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
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

        #[Url]
        public ?string $search = null;

        #[Computed]
        public function references(): LengthAwarePaginatorContract
        {
            $search = $this->normalizedSearch();

            if ($search === null) {
                return $this->baseReferencesQuery()
                    ->root()
                    ->orderBy('references.title')
                    ->paginate(12)
                    ->withQueryString();
            }

            $directMatches = $this->directSearch($search);

            if ($directMatches->total() > 0 || mb_strlen($search) < 3) {
                return $directMatches;
            }

            return $this->fuzzySearch($search);
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

        private function baseReferencesQuery(bool $includeParts = false): Builder
        {
            $query = Reference::query()
                ->active()
                ->where('status', 'verified')
                ->withCount(['events' => function (Builder $query): void {
                    $query->active();
                }])
                ->with('media');

            if (! $includeParts) {
                $query->root();
            }

            return $query;
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

            $scopedIds = $this->baseReferencesQuery(includeParts: true)
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
@section('og_image', asset('images/default-mosque-hero.png'))
@section('og_image_alt', __('Reference directory'))
@section('og_image_width', '1024')
@section('og_image_height', '1024')

@php
    $references = $this->references;
    $search = $this->search;
    $referenceTotal = $references->total();
    $referenceLoadingTarget = 'search,clearSearch';
@endphp

<div class="relative min-h-screen">
    <div class="relative pt-12 pb-16 bg-white border-b border-slate-100 overflow-hidden">
        <div class="absolute inset-0 bg-emerald-50/50"></div>
        <div class="absolute inset-0 opacity-5" style="background-image: url('{{ asset('images/pattern-bg.png') }}');"></div>

        <div class="container relative mx-auto px-6 text-center lg:px-12">
            <h1 class="mb-6 text-balance font-heading text-4xl font-extrabold tracking-tight text-slate-900 md:text-5xl">
                {{ __('Sources of') }} <br class="hidden md:block" />
                <span class="bg-linear-to-r from-emerald-600 to-teal-500 bg-clip-text text-transparent">{{ __('Knowledge & Guidance') }}</span>
            </h1>
            <p class="mx-auto max-w-2xl text-balance text-lg text-slate-600 md:text-xl">
                {{ __('Books, articles, videos, and reference works used across Majlis Ilmu in the ilmu360° community.') }}
            </p>

            <div class="mx-auto mt-8 max-w-xl">
                <div class="group relative">
                    <label for="reference-search" class="sr-only">{{ __('Search references') }}</label>
                    <input
                        type="text"
                        id="reference-search"
                        wire:model.live.debounce.300ms="search"
                        wire:keydown.escape="clearSearch"
                        placeholder="{{ __('Search references...') }}"
                        class="h-14 w-full rounded-2xl border-2 border-slate-200 bg-white py-0 pl-12 pr-4 font-medium text-slate-900 shadow-lg shadow-slate-200/60 transition-all placeholder:text-slate-400 focus:border-emerald-500 focus:outline-none focus:ring-4 focus:ring-emerald-500/10"
                    >
                    <svg class="absolute left-4 top-1/2 h-6 w-6 -translate-y-1/2 text-slate-400 transition-colors group-focus-within:text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    @if(filled($search))
                        <button
                            type="button"
                            wire:click="clearSearch"
                            aria-label="{{ __('Clear search') }}"
                            class="absolute right-3 top-1/2 inline-flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full border border-red-200 bg-red-50 text-red-500 shadow-sm transition hover:border-red-300 hover:bg-red-100 hover:text-red-600 focus:outline-none focus:ring-4 focus:ring-red-500/10"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l8 8M14 6l-8 8" />
                            </svg>
                            <span class="sr-only">{{ __('Clear search') }}</span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="container mx-auto mt-12 px-6 lg:px-12">
        <div wire:loading.delay.short wire:target="{{ $referenceLoadingTarget }}">
            <x-ui.skeleton.institution-card-grid :items="8" columns="sm:grid-cols-2 lg:grid-cols-4" />
        </div>

        <div wire:loading.remove wire:target="{{ $referenceLoadingTarget }}">
            @if($references->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-200 bg-slate-50/50 py-24 text-center">
                    <div class="mb-6 inline-flex h-20 w-20 items-center justify-center rounded-full bg-white text-slate-300 shadow-sm">
                        <svg class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900">{{ __('No references found') }}</h3>
                    <p class="mx-auto mt-2 max-w-md text-slate-500">
                        {{ __('We couldn\'t find any references matching your search.') }}
                    </p>
                    <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                        <button type="button" wire:click="clearSearch" class="font-semibold text-emerald-600 hover:text-emerald-700">
                            {{ __('Clear Search') }} &rarr;
                        </button>
                    </div>
                </div>
            @else
                <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach($references as $reference)
                        @php
                            $coverUrl = $reference->getFirstMediaUrl('front_cover', 'thumb') ?: $reference->getFirstMediaUrl('back_cover', 'thumb');
                            $referenceType = \App\Enums\ReferenceType::tryFrom((string) $reference->type);
                            $typeLabel = $referenceType?->getLabel() ?? (filled($reference->type) ? \Illuminate\Support\Str::headline((string) $reference->type) : __('Reference'));
                            $metaParts = array_values(array_filter([
                                $reference->author,
                                $reference->publisher,
                                $reference->publication_year,
                            ], fn (mixed $value): bool => filled($value)));
                        @endphp

                        <a
                            href="{{ route('references.show', $reference) }}"
                            wire:navigate
                            class="group relative flex flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-md transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-emerald-900/8"
                        >
                            <div class="relative flex aspect-4/5 items-center justify-center overflow-hidden bg-linear-to-br from-slate-50 to-emerald-50">
                                @if($coverUrl)
                                    <img src="{{ $coverUrl }}" alt="{{ $reference->title }}" class="h-full w-full object-cover transition-transform duration-700 group-hover:scale-105" width="200" height="280" loading="lazy">
                                    <div class="absolute inset-0 bg-linear-to-t from-slate-900/55 via-slate-900/10 to-transparent"></div>
                                @else
                                    <svg class="h-20 w-20 text-emerald-200 transition-transform duration-700 group-hover:scale-110" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                    </svg>
                                @endif

                                <span class="absolute left-4 top-4 rounded-full bg-white/95 px-3 py-1 text-xs font-bold text-emerald-700 shadow-sm ring-1 ring-emerald-100">
                                    {{ $typeLabel }}
                                </span>
                            </div>

                            <div class="flex flex-1 flex-col p-6">
                                <h3 class="mb-2 line-clamp-2 font-heading text-lg font-bold leading-tight text-slate-900 transition-colors group-hover:text-emerald-700">
                                    {{ $reference->title }}
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

                                <div class="mt-auto flex items-center justify-center border-t border-slate-100 pt-5">
                                    <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                        <svg class="h-3.5 w-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        {{ $reference->events_count }} {{ __('Events') }}
                                    </span>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>

                <div class="mt-16">
                    {{ $references->links() }}
                </div>

                <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-4 text-center shadow-sm">
                    <p class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-400">{{ __('Direktori Rujukan') }}</p>
                    <p class="mt-2 text-sm font-semibold text-slate-600">
                        {{ __('Jumlah rujukan: :count', ['count' => number_format($referenceTotal)]) }}
                    </p>
                </div>
            @endif
        </div>
    </div>
</div>
