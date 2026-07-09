<?php

use App\Models\Speaker;
use App\Support\Search\SpeakerSearchService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
    #[Title('Speakers - ilmu360°')]
    class extends Component
    {
        use WithPagination;

        #[Url]
        public ?string $search = null;

        #[Computed]
        public function speakers(): LengthAwarePaginatorContract
        {
            $search = $this->normalizedSearch();

            if ($search === null) {
                return $this->baseSpeakersQuery()
                    ->publicDirectoryOrder()
                    ->paginate(12);
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

        private function baseSpeakersQuery(): Builder
        {
            return Speaker::query()
                ->active()
                ->where('status', 'verified')
                ->withCount(['events' => function ($query) {
                    $eventsTable = $query->getModel()->getTable();
                    $query->whereIn("{$eventsTable}.status", \App\Models\Event::PUBLIC_STATUSES)
                        ->where("{$eventsTable}.visibility", \App\Enums\EventVisibility::Public)
                        ->whereNotNull("{$eventsTable}.published_at")
                        ->where('starts_at', '>=', now());
                }])
                ->with('media');
        }

        private function directSearch(string $search): LengthAwarePaginatorContract
        {
            $matchingIds = $this->speakerSearchService()->publicSearchIds($search);

            if ($matchingIds === []) {
                return $this->emptyPaginator();
            }

            return $this->baseSpeakersQuery()
                ->whereIn('speakers.id', $matchingIds)
                ->publicDirectoryOrder()
                ->paginate(12);
        }

        private function fuzzySearch(string $search): LengthAwarePaginatorContract
        {
            $orderedIds = $this->speakerSearchService()->publicFuzzySearchIds($search);

            if ($orderedIds === []) {
                return $this->emptyPaginator();
            }

            $currentPage = max(1, (int) $this->getPage());
            $perPage = 12;
            $paginationMeta = $this->paginationMeta();
            $paginatedIds = array_slice($orderedIds, ($currentPage - 1) * $perPage, $perPage);

            if ($paginatedIds === []) {
                return new LengthAwarePaginator(collect(), count($orderedIds), $perPage, $currentPage, $paginationMeta);
            }

            $speakers = $this->baseSpeakersQuery()
                ->whereIn('id', $paginatedIds)
                ->get()
                ->sortBy(static function (Speaker $speaker) use ($paginatedIds): int {
                    $position = array_search($speaker->id, $paginatedIds, true);

                    return is_int($position) ? $position : PHP_INT_MAX;
                })
                ->values();

            return new LengthAwarePaginator($speakers, count($orderedIds), $perPage, $currentPage, $paginationMeta);
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

        private function speakerSearchService(): SpeakerSearchService
        {
            return app(SpeakerSearchService::class);
        }
    };
?>

@section('title', __('Direktori Penceramah Islam') . ' - ' . config('app.name'))
@section('meta_description', __('Cari profil penceramah, ustaz, dan pendakwah serta semak majlis ilmu mereka yang akan datang di seluruh Malaysia.'))
@section('og_url', route('speakers.index'))
@section('og_image', asset('images/placeholders/speaker.png'))
@section('og_image_alt', __('Direktori penceramah Islam'))
@section('og_image_width', '1024')
@section('og_image_height', '1024')

@php
    $speakers = $this->speakers;
    $search = $this->search;
    $speakerLoadingTarget = 'search,clearSearch';
    $submitSpeakerUrl = route('contributions.submit-speaker');
    $speakerTotal = $speakers->total();
@endphp

<div class="relative min-h-screen">
    <!-- Hero Section -->
    <div class="relative pt-12 pb-16 bg-white border-b border-slate-100 overflow-hidden">
        <div class="absolute inset-0 bg-emerald-50/50"></div>
        <div class="absolute inset-0 opacity-5" style="background-image: url('{{ asset('images/pattern-bg.png') }}');"></div>

        <div class="container relative mx-auto px-6 lg:px-12 text-center">
            <h1
                class="font-heading text-4xl md:text-5xl font-extrabold text-slate-900 tracking-tight text-balance mb-6">
                {{ __('Voices of') }} <br class="hidden md:block" />
                <span
                    class="text-transparent bg-clip-text bg-gradient-to-r from-emerald-600 to-teal-500">{{ __('Knowledge & Wisdom') }}</span>
            </h1>
            <p class="text-slate-600 text-lg md:text-xl max-w-2xl mx-auto text-balance">
                {{ __('Scholars, teachers, and speakers sharing Majlis Ilmu with the community.') }}
            </p>

            <!-- Search Box -->
            <div class="max-w-xl mx-auto mt-8">
                <div class="relative group">
                    <label for="speaker-search" class="sr-only">{{ __('Search speakers') }}</label>
                    <input type="text" id="speaker-search" wire:model.live.debounce.300ms="search"
                        wire:keydown.escape="clearSearch"
                        placeholder="{{ __('Search speakers...') }}"
                        class="w-full h-14 pl-12 pr-4 rounded-2xl border-2 border-slate-200 bg-white shadow-lg shadow-slate-200/60 font-medium text-slate-900 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 focus:outline-none transition-all placeholder:text-slate-400">
                    <svg class="absolute left-4 top-1/2 -translate-y-1/2 h-6 w-6 text-slate-400 group-focus-within:text-emerald-500 transition-colors"
                        fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    @if(filled($search))
                        <button type="button" wire:click="clearSearch"
                            aria-label="{{ __('Clear search') }}"
                            class="absolute right-3 top-1/2 inline-flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full border border-red-200 bg-red-50 text-red-500 shadow-sm transition hover:border-red-300 hover:bg-red-100 hover:text-red-600 focus:outline-none focus:ring-4 focus:ring-red-500/10">
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

    <div class="container mx-auto px-6 lg:px-12 mt-12">
        <div wire:loading.delay.short wire:target="{{ $speakerLoadingTarget }}">
            <x-ui.skeleton.speaker-card-grid />
        </div>

        <div wire:loading.remove wire:target="{{ $speakerLoadingTarget }}">
        @if($speakers->isEmpty())
            <div class="text-center py-24 rounded-3xl bg-slate-50/50 border border-dashed border-slate-200">
                <div
                    class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-white text-slate-300 shadow-sm mb-6">
                    <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-slate-900">{{ __('No speakers found') }}</h3>
                <p class="text-slate-500 mt-2 max-w-md mx-auto">
                    {{ __('We couldn\'t find any speakers matching your search.') }}
                </p>
                <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                    <button type="button" wire:click="clearSearch"
                        class="font-semibold text-emerald-600 hover:text-emerald-700">
                        {{ __('Clear Search') }} &rarr;
                    </button>
                </div>
            </div>
        @else
            <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-8">
                @foreach($speakers as $speaker)
                    <a href="{{ route('speakers.show', $speaker) }}" wire:navigate
                        class="group relative bg-white rounded-3xl border border-slate-200 shadow-md hover:shadow-xl hover:shadow-emerald-900/8 hover:-translate-y-1 transition-all duration-300 flex flex-col items-center text-center p-8 overflow-hidden z-10">

                        <!-- Background Decoration -->
                        <div
                            class="absolute inset-x-0 top-0 h-24 bg-gradient-to-b from-emerald-50/80 to-transparent -z-10 opacity-60 transition-opacity group-hover:opacity-100">
                        </div>

                        <div
                            class="h-32 w-32 rounded-full p-1.5 bg-white ring-2 ring-slate-200 group-hover:ring-emerald-300 shadow-lg mb-6 relative group-hover:scale-105 transition-all duration-500">
                            <div class="w-full h-full rounded-full overflow-hidden bg-emerald-50 relative">
                                <img src="{{ $speaker->public_avatar_url }}" alt="{{ $speaker->name }}"
                                    class="w-full h-full object-cover" width="128" height="128" loading="lazy">
                            </div>
                        </div>

                        <h3
                            class="font-heading text-lg font-bold text-slate-900 group-hover:text-emerald-700 transition-colors mb-2 leading-tight">
                            {{ $speaker->formatted_name }}
                        </h3>

                        <div class="mt-auto pt-4 w-full border-t border-slate-100">
                            <div class="flex items-center justify-center">
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    {{ $speaker->events_count }} {{ __('Events') }}
                                </span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-16">
                {{ $speakers->links() }}
            </div>

            <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-4 text-center shadow-sm">
                <p class="text-[11px] font-black uppercase tracking-[0.2em] text-slate-400">{{ __('Direktori Penceramah') }}</p>
                <p class="mt-2 text-sm font-semibold text-slate-600">
                    {{ __('Jumlah penceramah: :count', ['count' => number_format($speakerTotal)]) }}
                </p>
            </div>
        @endif

        <section class="mt-16">
            <div class="relative overflow-hidden rounded-[2rem] border border-emerald-200/70 bg-gradient-to-br from-emerald-600 via-teal-600 to-cyan-600 px-6 py-8 text-white shadow-[0_30px_90px_-40px_rgba(5,150,105,0.85)] md:px-10 md:py-10">
                <div class="absolute -right-16 -top-16 h-44 w-44 rounded-full bg-white/10 blur-2xl"></div>
                <div class="absolute -bottom-20 left-0 h-48 w-48 rounded-full bg-emerald-300/20 blur-3xl"></div>

                <div class="relative flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                    <div class="max-w-2xl">
                        <span class="inline-flex items-center rounded-full border border-white/20 bg-white/10 px-3 py-1 text-[11px] font-black uppercase tracking-[0.22em] text-emerald-50">
                            {{ __('Sumbangan Komuniti') }}
                        </span>
                        <h2 class="mt-4 font-heading text-2xl font-bold tracking-tight text-balance md:text-3xl">
                            {{ __('Tak jumpa penceramah yang anda cari? Cadangkan profil baharu.') }}
                        </h2>
                        <p class="mt-3 max-w-2xl text-sm leading-6 text-emerald-50/90 md:text-base">
                            {{ __('Bantu kami tambah ustaz, ustazah, asatizah, dan pendakwah yang patut ditemui ramai. Hantaran anda akan disemak dahulu sebelum dipaparkan kepada umum.') }}
                        </p>
                    </div>

                    <div class="flex flex-col items-start gap-3 lg:items-end">
                        <a
                            href="{{ $submitSpeakerUrl }}"
                            wire:navigate
                            class="group inline-flex min-w-[18rem] items-center justify-between gap-4 rounded-[1.5rem] bg-white px-5 py-4 text-left text-emerald-700 shadow-xl shadow-emerald-950/20 transition duration-200 hover:-translate-y-0.5 hover:bg-emerald-50"
                        >
                            <span class="block">
                                <span class="block text-[11px] font-black uppercase tracking-[0.2em] text-emerald-500">{{ __('Tambah ke direktori') }}</span>
                                <span class="mt-1 block text-base font-bold text-emerald-900">{{ __('Cadangkan penceramah baharu') }}</span>
                            </span>
                            <svg class="h-5 w-5 shrink-0 transition group-hover:translate-x-1" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.167 10h11.666m0 0-4.166-4.167M15.833 10l-4.166 4.167" />
                            </svg>
                        </a>

                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

    <x-filament-actions::modals />
</div>
