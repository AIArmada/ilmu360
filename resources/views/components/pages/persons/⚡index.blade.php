<?php

use App\Models\Person;
use App\Support\Search\PersonSearchService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
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

        #[Url]
        public ?string $search = null;

        #[Url]
        public ?string $sort = null;

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
                return $this->applySort($this->basePersonsQuery())
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

        private function basePersonsQuery(): Builder
        {
            return Person::query()
                ->active()
                ->speakers()
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
            $matchingIds = $this->personSearchService()->publicSearchIds($search);

            if ($matchingIds === []) {
                return $this->emptyPaginator();
            }

            return $this->applySort($this->basePersonsQuery()
                ->whereIn('persons.id', $matchingIds))
                ->paginate(12);
        }

        private function fuzzySearch(string $search): LengthAwarePaginatorContract
        {
            $orderedIds = $this->personSearchService()->publicFuzzySearchIds($search);

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

            $persons = $this->basePersonsQuery()
                ->whereIn('id', $paginatedIds)
                ->get()
                ->sortBy(static function (Person $person) use ($paginatedIds): int {
                    $position = array_search($person->id, $paginatedIds, true);

                    return is_int($position) ? $position : PHP_INT_MAX;
                })
                ->values();

            return new LengthAwarePaginator($persons, count($orderedIds), $perPage, $currentPage, $paginationMeta);
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

        private function personSearchService(): PersonSearchService
        {
            return app(PersonSearchService::class);
        }
    };
?>

@section('title', __('Direktori Penceramah Islam') . ' - ' . config('app.name'))
@section('meta_description', __('Cari profil penceramah, ustaz, dan pendakwah serta semak majlis ilmu mereka yang akan datang di seluruh Malaysia.'))
@section('og_url', route('persons.index'))
@section('og_image', asset('images/placeholders/person.png'))
@section('og_image_alt', __('Direktori penceramah Islam'))
@section('og_image_width', '1024')
@section('og_image_height', '1024')

@php
    $persons = $this->persons;
    $search = $this->search;
    $personLoadingTarget = 'search,clearSearch';
    $submitPersonUrl = route('contributions.submit-person');
    $personTotal = $persons->total();
@endphp

<div class="relative min-h-screen overflow-x-clip bg-[#fafaf7] text-slate-800">
    <!-- Hero Section -->
    <div class="relative overflow-hidden border-b border-emerald-900/[0.06]">
        <!-- Background layers -->
        <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_18%_28%,rgba(5,101,82,0.10)_0%,transparent_42%),radial-gradient(ellipse_at_82%_18%,rgba(217,119,6,0.06)_0%,transparent_36%),linear-gradient(178deg,#fafaf7_0%,#f4f1e8_54%,#e7eee8_100%)]"></div>

        <div class="relative mx-auto max-w-7xl px-5 py-14 sm:px-6 sm:py-20 lg:px-8 lg:py-24">
            <div class="max-w-3xl scroll-reveal reveal-left revealed" x-intersect.once="$el.classList.add('revealed')" style="--reveal-d: 80ms">
                    <!-- Eyebrow -->
                    <div class="inline-flex items-center gap-2.5 rounded-full border border-emerald-200/60 bg-emerald-50/80 px-3.5 py-1.5 text-[11px] font-black uppercase tracking-[0.20em] text-emerald-700 shadow-sm backdrop-blur-sm">
                        <span class="relative flex h-2 w-2">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400/60"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                        </span>
                        {{ __('Direktori Disahkan') }}
                    </div>

                    <h1 class="mt-6 max-w-3xl font-heading text-4xl font-bold leading-[1.06] tracking-[-0.035em] text-emerald-950 sm:text-5xl lg:text-6xl">
                        {{ __('Temui penceramah yang') }}
                        <span class="relative inline-block text-emerald-700">
                            {{ __('diyakini dan berilmu') }}
                            <svg class="absolute -bottom-2 left-0 h-3.5 w-full text-amber-500/80" viewBox="0 0 320 18" preserveAspectRatio="none" aria-hidden="true">
                                <path d="M4 13C79 5 218 4 316 10" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round" />
                            </svg>
                        </span>
                    </h1>

                    <p class="mt-6 max-w-xl text-base leading-7 text-slate-600 sm:mt-7 sm:text-lg">
                        {{ __('Cari ustaz, ustazah dan pendakwah daripada seluruh Malaysia. Kenali mereka, lihat majlis akan datang dan teruskan perjalanan menuntut ilmu.') }}
                    </p>

                    <p class="mt-3 text-sm font-medium text-emerald-700">
                        {{ __('Setiap profil disemak sebelum diterbitkan.') }}
                    </p>

                    <!-- Search Box - refined pill -->
                    <div class="mt-9 max-w-xl">
                        <div class="group relative rounded-[1.5rem] border border-white/80 bg-white/90 p-1.5 shadow-[0_20px_60px_-28px_rgba(6,78,59,0.40),0_4px_12px_-2px_rgba(0,0,0,0.04)] backdrop-blur-xl transition-all duration-300 focus-within:scale-[1.01] focus-within:border-emerald-300 focus-within:ring-4 focus-within:ring-emerald-600/10 focus-within:shadow-[0_28px_70px_-30px_rgba(6,78,59,0.50)]">
                            <label for="person-search" class="sr-only">{{ __('Cari penceramah') }}</label>
                            <div class="flex items-center gap-3">
                                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-emerald-50 text-emerald-700 transition-colors duration-300 group-focus-within:bg-emerald-100">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m1.35-5.4a6.75 6.75 0 1 1-13.5 0 6.75 6.75 0 0 1 13.5 0Z" />
                                    </svg>
                                </span>

                                <input
                                    type="search"
                                    id="person-search"
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
        <!-- Loading Skeleton -->
        <div wire:loading.delay.short wire:target="{{ $personLoadingTarget }}">
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

                        <h2 class="mt-6 font-heading text-2xl font-bold text-emerald-950">{{ __('Penceramah tidak ditemui') }}</h2>
                        <p class="mt-3 max-w-sm text-sm leading-6 text-slate-500">
                            @if(filled($search))
                                {{ __('Tiada profil sepadan dengan “:search”. Cuba ejaan berbeza atau gunakan nama penuh.', ['search' => $search]) }}
                            @else
                                {{ __('Direktori penceramah kosong buat masa ini. Sila kembali kemudian.') }}
                            @endif
                        </p>

                        <div class="mt-8 flex flex-col items-center gap-3 sm:flex-row">
                            @if(filled($search))
                                <button
                                    type="button"
                                    wire:click="clearSearch"
                                    class="inline-flex h-11 items-center justify-center gap-2.5 rounded-xl bg-emerald-800 px-5 text-sm font-bold text-white shadow-lg shadow-emerald-900/15 transition-all duration-200 hover:-translate-y-0.5 hover:bg-emerald-700 hover:shadow-xl hover:shadow-emerald-900/20"
                                >
                                    {{ __('Lihat semua penceramah') }}
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5 5 5-5 5" />
                                    </svg>
                                </button>
                            @endif

                            <a
                                href="{{ $submitPersonUrl }}"
                                wire:navigate
                                class="inline-flex h-11 items-center justify-center gap-2.5 rounded-xl border-2 border-emerald-200 bg-white px-5 text-sm font-bold text-emerald-700 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-emerald-300 hover:bg-emerald-50 hover:shadow-md"
                            >
                                {{ __('Cadangkan penceramah') }}
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14" />
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>
            @else
                <!-- Results Header -->
                <div class="mb-8 flex items-end justify-between gap-4 border-b border-slate-200/70 pb-6">
                    <div>
                        <h2 class="font-heading text-2xl font-bold tracking-tight text-emerald-950 sm:text-3xl">
                            @if(filled($search))
                                {{ __('Hasil carian untuk “:search”', ['search' => $search]) }}
                            @else
                                {{ __('Direktori Penceramah') }}
                            @endif
                        </h2>
                        <p class="mt-2 text-sm text-slate-500">
                            {{ trans_choice(':count penceramah ditemui|:count penceramah ditemui', $personTotal, ['count' => number_format($personTotal)]) }}
                        </p>
                    </div>

                    @unless(filled($search))
                        <div class="flex shrink-0 items-center gap-1 rounded-xl border border-slate-200/80 bg-white p-0.5 shadow-sm">
                            <button
                                type="button"
                                wire:click="$set('sort', null)"
                                class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-all duration-150 {{ $sort === null ? 'bg-emerald-800 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800' }}"
                            >
                                {{ __('Rawak') }}
                            </button>
                            <button
                                type="button"
                                wire:click="$set('sort', 'name')"
                                class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-all duration-150 {{ $sort === 'name' ? 'bg-emerald-800 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800' }}"
                            >
                                {{ __('Nama A–Z') }}
                            </button>
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
                            class="group relative flex min-h-[10rem] gap-0 overflow-hidden rounded-[1.5rem] border border-slate-200/80 bg-white transition-all duration-300 hover:-translate-y-1.5 hover:border-emerald-300/80 hover:shadow-[0_22px_50px_-28px_rgba(6,78,59,0.40)] sm:block sm:min-h-0"
                        >
                            <!-- Image area -->
                            <div class="relative w-28 shrink-0 overflow-hidden bg-gradient-to-br from-[#faf5e8] via-[#eff5f1] to-[#dce9e2] sm:w-full sm:aspect-[3/4]">
                                <!-- Dot pattern overlay -->
                                <div class="absolute inset-0 opacity-[0.15]" style="background-image: radial-gradient(circle at 1.5px 1.5px, rgba(7,91,72,.14) 1px, transparent 0); background-size: 16px 16px;"></div>
                                @php
                                    $initials = str($person->name)->explode(' ')
                                        ->reject(fn(string $w): bool => in_array(strtolower($w), ['bin', 'binti', 'ibni', 'ibn', 'binte', 'abd', 'abdul', 'abu'], true))
                                        ->take(2)
                                        ->map(fn(string $w): string => str($w)->substr(0, 1)->upper())
                                        ->implode('');
                                @endphp

                                @if($person->hasMedia('main'))
                                    <img
                                        src="{{ $person->public_main_url }}"
                                        alt="{{ $person->formatted_name }}"
                                        class="relative h-full w-full object-cover object-top transition duration-500 ease-out group-hover:scale-[1.04]"
                                        width="320"
                                        height="427"
                                        loading="lazy"
                                    >
                                @else
                                    <div class="flex h-full w-full items-center justify-center">
                                        <span class="font-heading text-[clamp(1.5rem,4vw,2.75rem)] font-bold tracking-tight text-emerald-800/30" aria-hidden="true">{{ $initials }}</span>
                                    </div>
                                @endif

                                <!-- Gradient fade at bottom -->
                                <div class="absolute inset-x-0 bottom-0 hidden h-28 bg-gradient-to-t from-emerald-950/80 via-emerald-950/30 to-transparent sm:block"></div>

                                <!-- Verified checkmark -->
                                <span class="absolute left-2.5 top-2.5 grid h-7 w-7 place-items-center rounded-full border border-white/60 bg-white/90 text-emerald-700 shadow-sm backdrop-blur sm:left-3 sm:top-3" title="{{ __('Disahkan') }}">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.051l-7.5 9.75a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.897 3.896 6.976-9.07a.75.75 0 0 1 1.051-.142Z" clip-rule="evenodd" />
                                    </svg>
                                </span>

                                <!-- Arrow affordance -->
                                <div class="absolute inset-x-3 bottom-3 hidden items-center justify-end gap-2 text-white sm:flex">
                                    <svg class="h-4 w-4 transition-transform duration-300 group-hover:translate-x-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5 5 5-5 5" />
                                    </svg>
                                </div>
                            </div>

                            <!-- Content area -->
                            <div class="flex min-w-0 flex-1 flex-col p-4 sm:p-5">
                                <h3 class="font-heading text-lg font-bold leading-tight tracking-[-0.02em] text-emerald-950 transition-colors duration-200 group-hover:text-emerald-700">
                                    {{ $person->formatted_name }}
                                </h3>

                                <div class="mt-3 flex items-center gap-2.5 text-xs text-slate-500">
                                    <span class="grid h-7 w-7 shrink-0 place-items-center rounded-xl bg-amber-50 text-amber-700 ring-1 ring-amber-100">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2Z" />
                                        </svg>
                                    </span>
                                    <span>
                                        <strong class="font-bold text-slate-800">{{ number_format($person->events_count) }}</strong>
                                        {{ trans_choice('majlis akan datang|majlis akan datang', $person->events_count) }}
                                    </span>
                                </div>

                                <div class="mt-auto pt-4">
                                    <span class="inline-flex items-center gap-2 text-xs font-bold text-emerald-700 transition-colors duration-200 group-hover:text-emerald-600 sm:text-sm">
                                        {{ __('Lihat profil') }}
                                        <svg class="h-3.5 w-3.5 transition-transform duration-300 group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
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
                    <div class="mt-10 rounded-2xl border border-slate-200/80 bg-white px-5 py-4 shadow-sm">
                        {{ $persons->links() }}
                    </div>
                @endif
            @endif

            <!-- Community Contribution CTA -->
            <section class="mt-14 sm:mt-20">
                <div class="relative overflow-hidden rounded-[1.5rem] border border-emerald-800/15 bg-emerald-950 px-6 py-10 text-white shadow-[0_28px_80px_-38px_rgba(6,78,59,0.85)] sm:px-8 md:px-10 md:py-12">
                    <!-- Decorative elements -->
                    <div class="absolute inset-0 opacity-[0.12]" style="background-image: radial-gradient(circle at 1.5px 1.5px, rgba(255,255,255,.70) 1px, transparent 0); background-size: 22px 22px;"></div>
                    <div class="absolute -right-20 -top-20 h-56 w-56 rounded-full border border-white/[0.08]"></div>
                    <div class="absolute -right-8 -top-8 h-32 w-32 rounded-full border border-amber-300/[0.15]"></div>
                    <div class="absolute -bottom-16 -left-16 h-48 w-48 rounded-full bg-emerald-700/[0.15] blur-3xl"></div>

                    <div class="relative flex flex-col gap-8 lg:flex-row lg:items-center lg:justify-between">
                        <div class="max-w-2xl">
                            <h2 class="max-w-xl font-heading text-2xl font-bold leading-snug tracking-tight text-balance sm:text-3xl">
                                {{ __('Kenal penceramah yang belum tersenarai?') }}
                            </h2>
                            <p class="mt-4 max-w-2xl text-sm leading-6 text-emerald-100/75 sm:text-base">
                                {{ __('Bantu masyarakat menemui lebih banyak guru dan pendakwah. Setiap cadangan akan melalui proses semakan sebelum diterbitkan.') }}
                            </p>
                        </div>

                        <a
                            href="{{ $submitPersonUrl }}"
                            wire:navigate
                            class="group inline-flex min-h-14 w-full items-center justify-between gap-5 rounded-[1.25rem] bg-white px-5 py-3.5 text-left text-emerald-900 shadow-xl shadow-black/15 transition-all duration-200 hover:-translate-y-0.5 hover:bg-amber-50 hover:shadow-2xl hover:shadow-black/20 sm:w-auto sm:min-w-[18rem]"
                        >
                            <span class="text-sm font-bold sm:text-base">{{ __('Cadangkan penceramah') }}</span>
                            <svg class="h-5 w-5 shrink-0 transition-transform duration-300 group-hover:translate-x-1.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
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
