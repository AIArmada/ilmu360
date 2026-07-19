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

<div class="relative min-h-screen overflow-x-clip bg-[#f7f5ef] text-slate-900">
    <!-- Hero Section -->
    <div class="relative overflow-hidden border-b border-emerald-950/10 bg-[#f3efe5]">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_12%_18%,rgba(214,164,66,0.20),transparent_28%),radial-gradient(circle_at_86%_22%,rgba(5,101,82,0.16),transparent_34%),linear-gradient(135deg,#fffdf8_0%,#f4efe4_56%,#e8eee8_100%)]"></div>
        <div class="absolute inset-0 opacity-[0.08]" style="background-image: url('{{ asset('images/pattern-bg.png') }}');"></div>
        <div class="absolute -right-24 -top-24 h-80 w-80 rounded-full border border-emerald-800/10"></div>
        <div class="absolute -right-8 -top-8 h-52 w-52 rounded-full border border-amber-500/15"></div>

        <div class="relative mx-auto max-w-7xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8 lg:py-20">
            <div class="grid items-center gap-10 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <div class="max-w-3xl">
                    <div class="inline-flex items-center gap-2 rounded-full border border-amber-700/15 bg-white/75 px-3 py-1.5 text-[11px] font-black uppercase tracking-[0.22em] text-amber-800 shadow-sm backdrop-blur">
                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                        {{ __('Direktori Penceramah') }}
                    </div>

                    <h1 class="mt-5 max-w-3xl font-heading text-4xl font-bold leading-[1.04] tracking-[-0.035em] text-emerald-950 sm:text-5xl lg:text-6xl">
                        {{ __('Temui penceramah yang') }}
                        <span class="relative inline-block text-emerald-700">
                            {{ __('diyakini dan berilmu') }}
                            <svg class="absolute -bottom-2 left-0 h-3 w-full text-amber-500/70" viewBox="0 0 320 18" preserveAspectRatio="none" aria-hidden="true">
                                <path d="M3 13C78 4 224 3 317 10" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" />
                            </svg>
                        </span>
                    </h1>

                    <p class="mt-7 max-w-2xl text-base leading-7 text-slate-600 sm:text-lg">
                        {{ __('Cari ustaz, ustazah dan pendakwah daripada seluruh Malaysia. Kenali mereka, lihat majlis akan datang dan teruskan perjalanan menuntut ilmu.') }}
                    </p>

                    <!-- Search Box -->
                    <div class="mt-8 max-w-3xl">
                        <div class="group relative rounded-[1.35rem] border border-white/90 bg-white/90 p-2 shadow-[0_24px_70px_-30px_rgba(6,78,59,0.48)] backdrop-blur-xl transition focus-within:border-emerald-300 focus-within:ring-4 focus-within:ring-emerald-600/10">
                            <label for="speaker-search" class="sr-only">{{ __('Cari penceramah') }}</label>
                            <div class="flex items-center gap-3">
                                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-emerald-50 text-emerald-700">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m1.35-5.4a6.75 6.75 0 1 1-13.5 0 6.75 6.75 0 0 1 13.5 0Z" />
                                    </svg>
                                </span>

                                <input
                                    type="search"
                                    id="speaker-search"
                                    wire:model.live.debounce.300ms="search"
                                    wire:keydown.escape="clearSearch"
                                    placeholder="{{ __('Cari nama penceramah…') }}"
                                    autocomplete="off"
                                    class="h-12 min-w-0 flex-1 border-0 bg-transparent px-0 text-base font-medium text-slate-900 placeholder:text-slate-400 focus:ring-0"
                                >

                                @if(filled($search))
                                    <button
                                        type="button"
                                        wire:click="clearSearch"
                                        wire:loading.attr="disabled"
                                        wire:target="clearSearch"
                                        aria-label="{{ __('Kosongkan carian') }}"
                                        class="grid h-10 w-10 shrink-0 place-items-center rounded-full border border-slate-200 bg-white text-slate-400 shadow-sm transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-600 focus:outline-none focus:ring-4 focus:ring-rose-500/10"
                                    >
                                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l8 8M14 6l-8 8" />
                                        </svg>
                                    </button>
                                @endif
                            </div>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                            <span class="font-semibold text-slate-600">{{ __('Cari mengikut nama:') }}</span>
                            <span class="rounded-full border border-amber-700/10 bg-white/65 px-3 py-1.5">{{ __('Ustaz') }}</span>
                            <span class="rounded-full border border-amber-700/10 bg-white/65 px-3 py-1.5">{{ __('Ustazah') }}</span>
                            <span class="rounded-full border border-amber-700/10 bg-white/65 px-3 py-1.5">{{ __('Dr.') }}</span>
                            <span class="rounded-full border border-amber-700/10 bg-white/65 px-3 py-1.5">{{ __('Pendakwah') }}</span>
                        </div>
                    </div>
                </div>

                <div class="hidden lg:block">
                    <div class="relative overflow-hidden rounded-[1.75rem] border border-white/80 bg-white/75 p-6 shadow-[0_24px_80px_-35px_rgba(6,78,59,0.45)] backdrop-blur-xl">
                        <div class="absolute -right-10 -top-10 h-32 w-32 rounded-full bg-amber-300/15 blur-2xl"></div>
                        <div class="relative">
                            <div class="flex items-center gap-4 border-b border-slate-200/70 pb-5">
                                <span class="grid h-12 w-12 place-items-center rounded-2xl bg-emerald-100 text-emerald-800 ring-1 ring-emerald-200/70">
                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372A3.375 3.375 0 0 0 21 16.125V14.25A3.375 3.375 0 0 0 17.625 10.875h-.75M15 19.128v-.003c0-1.113-.285-2.158-.786-3.068M15 19.128v.003c-2.456.867-5.902.867-8.358 0v-.003m8.358 0a4.501 4.501 0 0 0-8.358 0M6.642 19.128a9.38 9.38 0 0 1-2.267.372A3.375 3.375 0 0 1 1 16.125V14.25a3.375 3.375 0 0 1 3.375-3.375h.75m6.75-1.5A3.375 3.375 0 1 0 5.125 9.375a3.375 3.375 0 0 0 6.75 0Zm6.75-1.5a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                                    </svg>
                                </span>
                                <div>
                                    <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-400">{{ __('Penceramah Disahkan') }}</p>
                                    <p class="mt-1 font-heading text-3xl font-bold text-emerald-950">{{ number_format($speakerTotal) }}</p>
                                </div>
                            </div>

                            <div class="mt-5 space-y-4">
                                <div class="flex items-start gap-3">
                                    <span class="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-full bg-emerald-100 text-emerald-700">
                                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.051l-7.5 9.75a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.897 3.896 6.976-9.07a.75.75 0 0 1 1.051-.142Z" clip-rule="evenodd" />
                                        </svg>
                                    </span>
                                    <div>
                                        <p class="text-sm font-bold text-slate-800">{{ __('Profil yang disemak') }}</p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('Direktori awam hanya memaparkan penceramah aktif dan disahkan.') }}</p>
                                    </div>
                                </div>

                                <div class="flex items-start gap-3">
                                    <span class="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-full bg-amber-100 text-amber-700">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2Z" />
                                        </svg>
                                    </span>
                                    <div>
                                        <p class="text-sm font-bold text-slate-800">{{ __('Jadual majlis terkini') }}</p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('Lihat jumlah majlis akan datang terus daripada setiap profil.') }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
        <div wire:loading.delay.short wire:target="{{ $speakerLoadingTarget }}">
            <x-ui.skeleton.speaker-card-grid />
        </div>

        <div wire:loading.remove wire:target="{{ $speakerLoadingTarget }}">
            @if($speakers->isEmpty())
                <div class="overflow-hidden rounded-[1.75rem] border border-dashed border-emerald-200 bg-gradient-to-br from-white to-emerald-50/70 px-6 py-16 text-center shadow-sm sm:px-10 sm:py-20">
                    <div class="mx-auto grid h-20 w-20 place-items-center rounded-3xl bg-white text-emerald-300 shadow-lg shadow-emerald-900/5 ring-1 ring-emerald-100">
                        <svg class="h-9 w-9" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m1.35-5.4a6.75 6.75 0 1 1-13.5 0 6.75 6.75 0 0 1 13.5 0Z" />
                        </svg>
                    </div>
                    <h2 class="mt-6 font-heading text-2xl font-bold text-emerald-950">{{ __('Penceramah tidak ditemui') }}</h2>
                    <p class="mx-auto mt-3 max-w-md text-sm leading-6 text-slate-500">
                        {{ __('Tiada profil sepadan dengan carian anda. Cuba ejaan lain atau kosongkan carian untuk melihat seluruh direktori.') }}
                    </p>
                    <button
                        type="button"
                        wire:click="clearSearch"
                        class="mt-6 inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-emerald-800 px-5 text-sm font-bold text-white shadow-lg shadow-emerald-900/15 transition hover:-translate-y-0.5 hover:bg-emerald-700"
                    >
                        {{ __('Lihat semua penceramah') }}
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5 5 5-5 5" />
                        </svg>
                    </button>
                </div>
            @else
                <div class="mb-6 flex flex-col gap-4 border-b border-slate-200/80 pb-5 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-[11px] font-black uppercase tracking-[0.2em] text-amber-700">{{ __('Direktori Penceramah') }}</p>
                        <h2 class="mt-1 font-heading text-2xl font-bold tracking-tight text-emerald-950 sm:text-3xl">
                            @if(filled($search))
                                {{ __('Hasil carian untuk “:search”', ['search' => $search]) }}
                            @else
                                {{ __('Penceramah untuk diterokai') }}
                            @endif
                        </h2>
                        <p class="mt-2 text-sm text-slate-500">
                            {{ trans_choice(':count penceramah ditemui|:count penceramah ditemui', $speakerTotal, ['count' => number_format($speakerTotal)]) }}
                        </p>
                    </div>

                    <div class="inline-flex w-fit items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-800">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.051l-7.5 9.75a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.897 3.896 6.976-9.07a.75.75 0 0 1 1.051-.142Z" clip-rule="evenodd" />
                        </svg>
                        {{ __('Semua profil disahkan') }}
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach($speakers as $speaker)
                        <a
                            href="{{ route('speakers.show', $speaker) }}"
                            wire:key="speaker-directory-{{ $speaker->id }}"
                            wire:navigate
                            class="group relative grid min-h-[9.5rem] grid-cols-[7rem_minmax(0,1fr)] overflow-hidden rounded-[1.35rem] border border-slate-200/90 bg-white shadow-[0_12px_40px_-28px_rgba(15,23,42,0.50)] transition duration-300 hover:-translate-y-1 hover:border-emerald-300 hover:shadow-[0_24px_55px_-30px_rgba(6,78,59,0.45)] sm:block sm:min-h-0"
                        >
                            <div class="relative overflow-hidden bg-gradient-to-br from-[#f8f1e3] via-[#eef5f0] to-[#dbe8e1] sm:aspect-[4/4.6]">
                                <div class="absolute inset-0 opacity-40" style="background-image: radial-gradient(circle at 1px 1px, rgba(7,91,72,.16) 1px, transparent 0); background-size: 18px 18px;"></div>
                                <img
                                    src="{{ $speaker->public_avatar_url }}"
                                    alt="{{ $speaker->formatted_name }}"
                                    class="relative h-full w-full object-cover object-top transition duration-500 group-hover:scale-[1.035]"
                                    width="320"
                                    height="368"
                                    loading="lazy"
                                >

                                <div class="absolute inset-x-0 bottom-0 hidden h-24 bg-gradient-to-t from-emerald-950/75 to-transparent sm:block"></div>
                                <span class="absolute left-2 top-2 inline-flex items-center gap-1 rounded-full border border-white/60 bg-white/90 px-2 py-1 text-[9px] font-black uppercase tracking-wide text-emerald-800 shadow-sm backdrop-blur sm:left-3 sm:top-3">
                                    <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.051l-7.5 9.75a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.897 3.896 6.976-9.07a.75.75 0 0 1 1.051-.142Z" clip-rule="evenodd" />
                                    </svg>
                                    {{ __('Disahkan') }}
                                </span>

                                <div class="absolute inset-x-3 bottom-3 hidden items-center justify-between gap-2 text-white sm:flex">
                                    <span class="text-[11px] font-bold uppercase tracking-[0.15em] text-white/85">{{ __('Penceramah') }}</span>
                                    <svg class="h-4 w-4 transition group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5 5 5-5 5" />
                                    </svg>
                                </div>
                            </div>

                            <div class="flex min-w-0 flex-col p-4 sm:p-5">
                                <h3 class="font-heading text-lg font-bold leading-tight tracking-[-0.02em] text-emerald-950 transition group-hover:text-emerald-700 sm:min-h-[2.75rem]">
                                    {{ $speaker->formatted_name }}
                                </h3>

                                <div class="mt-3 flex items-center gap-2 text-xs text-slate-500">
                                    <span class="grid h-7 w-7 shrink-0 place-items-center rounded-xl bg-amber-50 text-amber-700 ring-1 ring-amber-100">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2Z" />
                                        </svg>
                                    </span>
                                    <span>
                                        <strong class="font-bold text-slate-800">{{ number_format($speaker->events_count) }}</strong>
                                        {{ trans_choice('majlis akan datang|majlis akan datang', $speaker->events_count) }}
                                    </span>
                                </div>

                                <div class="mt-auto pt-4">
                                    <span class="inline-flex items-center gap-2 text-xs font-bold text-emerald-700 transition group-hover:text-emerald-600 sm:text-sm">
                                        {{ __('Lihat profil') }}
                                        <svg class="h-3.5 w-3.5 transition group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5 5 5-5 5" />
                                        </svg>
                                    </span>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>

                <div class="mt-10 rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                    {{ $speakers->links() }}
                </div>
            @endif

            <section class="mt-12 sm:mt-16">
                <div class="relative overflow-hidden rounded-[2rem] border border-emerald-800/20 bg-emerald-950 px-6 py-8 text-white shadow-[0_30px_90px_-40px_rgba(6,78,59,0.9)] sm:px-8 md:px-10 md:py-10">
                    <div class="absolute inset-0 opacity-20" style="background-image: radial-gradient(circle at 1px 1px, rgba(255,255,255,.65) 1px, transparent 0); background-size: 24px 24px;"></div>
                    <div class="absolute -right-16 -top-16 h-48 w-48 rounded-full border border-white/10"></div>
                    <div class="absolute -right-6 -top-6 h-28 w-28 rounded-full border border-amber-300/20"></div>

                    <div class="relative flex flex-col gap-7 lg:flex-row lg:items-center lg:justify-between">
                        <div class="max-w-2xl">
                            <span class="inline-flex items-center rounded-full border border-amber-300/20 bg-amber-300/10 px-3 py-1 text-[11px] font-black uppercase tracking-[0.22em] text-amber-200">
                                {{ __('Sumbangan Komuniti') }}
                            </span>
                            <h2 class="mt-4 max-w-xl font-heading text-2xl font-bold tracking-tight text-balance sm:text-3xl">
                                {{ __('Kenal penceramah yang belum tersenarai?') }}
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-emerald-100/80 sm:text-base">
                                {{ __('Bantu masyarakat menemui lebih banyak guru dan pendakwah. Setiap cadangan akan melalui proses semakan sebelum diterbitkan.') }}
                            </p>
                        </div>

                        <a
                            href="{{ $submitSpeakerUrl }}"
                            wire:navigate
                            class="group inline-flex min-h-14 w-full items-center justify-between gap-4 rounded-[1.25rem] bg-white px-5 py-3.5 text-left text-emerald-900 shadow-xl shadow-black/15 transition hover:-translate-y-0.5 hover:bg-amber-50 sm:w-auto sm:min-w-[18rem]"
                        >
                            <span>
                                <span class="block text-[10px] font-black uppercase tracking-[0.2em] text-amber-700">{{ __('Tambah ke direktori') }}</span>
                                <span class="mt-1 block text-sm font-bold sm:text-base">{{ __('Cadangkan penceramah') }}</span>
                            </span>
                            <svg class="h-5 w-5 shrink-0 transition group-hover:translate-x-1" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.167 10h11.666m0 0-4.166-4.167M15.833 10l-4.166 4.167" />
                            </svg>
                        </a>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <x-filament-actions::modals />
</div>