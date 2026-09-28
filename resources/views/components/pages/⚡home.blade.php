<?php

use AIArmada\Engagement\Contracts\EngagementManager;
use AIArmada\Engagement\Models\Bookmark;
use App\Models\Event;
use App\Models\Person;
use App\Models\User;
use App\Support\Auth\IntendedRedirect;
use App\Support\Events\EventDetailPresenter;
use App\Support\Location\AddressHierarchyFormatter;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new
    #[Title('ilmu360° - Cari Kuliah & Majlis Ilmu di Malaysia')]
    class extends Component
    {
        #[Computed]
        public function featuredEvents(): Collection
        {
            return Event::active()
                ->whereBetween('starts_at', [now(), now()->copy()->addDays(14)])
                ->with([
                    'institution',
                    'institution.addresses.areaAssignments.area',
                    'institution.addresses.state',
                    'persons.media',
                    'persons.titleAssignments.title.category',
                    'timeExpressions',
                    'media' => fn ($query) => $query
                        ->where('collection_name', 'cover')
                        ->ordered(),
                ])
                ->orderBy('starts_at')
                ->take(3)
                ->get();
        }

        public function toggleSave(string $eventId): void
        {
            $user = auth()->user();

            if (! $user instanceof User) {
                $this->redirect(IntendedRedirect::loginUrl(request()->fullUrl()), navigate: true);

                return;
            }

            $event = Event::query()
                ->active()
                ->whereKey($eventId)
                ->first();

            if (! $event instanceof Event || ! in_array((string) $event->status, Event::ENGAGEABLE_STATUSES, true)) {
                return;
            }

            $isSaved = Bookmark::forBookmarker($user)
                ->forBookmarkable($event)
                ->active()
                ->exists();

            if ($isSaved) {
                app(EngagementManager::class)->removeBookmark($user, $event);

                return;
            }

            app(EngagementManager::class)->bookmark($user, $event);
        }

        /**
         * @return list<string>
         */
        #[Computed]
        public function savedEventIds(): array
        {
            $user = auth()->user();

            if (! $user instanceof User) {
                return [];
            }

            $eventIds = $this->featuredEvents
                ->map(fn (Event $event): string => (string) $event->getKey())
                ->values()
                ->all();

            if ($eventIds === []) {
                return [];
            }

            return Bookmark::forBookmarker($user)
                ->whereIn('bookmarkable_id', $eventIds)
                ->active()
                ->pluck('bookmarkable_id')
                ->map(fn (mixed $eventId): string => (string) $eventId)
                ->values()
                ->all();
        }

        /**
         * @return array{event: Event, location: string, address: string, embed_url: string, directions_url: string}|null
         */
        #[Computed]
        public function featuredMapLocation(): ?array
        {
            foreach ($this->featuredEvents as $event) {
                if ((string) $event->delivery_mode === \App\Enums\EventFormat::Online->value) {
                    continue;
                }

                $presenter = new EventDetailPresenter($event);
                $location = $presenter->primaryLocationFor($event);
                $address = $location?->primaryAddress()
                    ?? $event->venue?->primaryAddress()
                    ?? $event->institution?->primaryAddress();
                $latitude = $location?->latitude ?? $address?->latitude ?? $address?->lat;
                $longitude = $location?->longitude ?? $address?->longitude ?? $address?->lng;
                $coordinates = filled($latitude) && filled($longitude)
                    ? (string) $latitude . ',' . (string) $longitude
                    : null;
                $googleMapsUrl = $location?->google_maps_url ?? $address?->google_maps_url;
                $addressDisplayLines = AddressHierarchyFormatter::displayLines($address);
                $mapQuery = implode(', ', array_filter([
                    $event->venue?->name ?? $event->institution?->name,
                    $presenter->locationLabel($location),
                    $address?->line1,
                    $address?->line2,
                    $addressDisplayLines['locality'] ?? null,
                    $addressDisplayLines['regional'] ?? null,
                    $address?->city,
                    $address?->state,
                ]));
                $normalizedMapQuery = null;

                if (filled($googleMapsUrl)) {
                    $queryString = parse_url((string) $googleMapsUrl, PHP_URL_QUERY);

                    if (is_string($queryString) && $queryString !== '') {
                        parse_str($queryString, $queryParameters);
                        $normalizedMapQuery = $queryParameters['query'] ?? $queryParameters['q'] ?? null;
                    }
                }

                if (! filled($normalizedMapQuery) && $coordinates !== null) {
                    $normalizedMapQuery = $coordinates;
                }

                if (! filled($normalizedMapQuery)) {
                    $normalizedMapQuery = $mapQuery;
                }

                if (! filled($normalizedMapQuery)) {
                    continue;
                }

                $addressLabel = implode(', ', array_filter([
                    $address?->line1,
                    $address?->line2,
                    $address?->postcode,
                    $addressDisplayLines['locality'] ?? null,
                    $addressDisplayLines['regional'] ?? null,
                ]));
                $directionsUrl = filled($googleMapsUrl)
                    ? (string) $googleMapsUrl
                    : ($coordinates !== null
                        ? 'https://www.google.com/maps/dir/?api=1&destination=' . urlencode($coordinates)
                        : 'https://www.google.com/maps/search/?api=1&query=' . urlencode($mapQuery));

                return [
                    'event' => $event,
                    'location' => $event->venue?->name
                        ?? $event->institution?->name
                        ?? $presenter->locationLabel($location)
                        ?? __('Lokasi majlis'),
                    'address' => $addressLabel !== '' ? $addressLabel : ($coordinates ?? __('Lokasi pada peta')),
                    'embed_url' => 'https://www.google.com/maps?q=' . urlencode((string) $normalizedMapQuery) . '&output=embed',
                    'directions_url' => $directionsUrl,
                ];
            }

            return null;
        }

        #[Computed]
        public function featuredPersons(): Collection
        {
            $featuredPersonSlugs = [
                'wadi-annuar',
                'azhar-idrus',
                'mohd-asri-zainul-abidin',
                'don-daniyal',
                'rozaimi-ramle',
            ];

            return Person::active()
                ->whereIn('persons.slug', $featuredPersonSlugs)
                ->with('media')
                ->get()
                ->sortBy(fn (Person $person): int => (int) array_search($person->slug, $featuredPersonSlugs, true))
                ->values();
        }

        #[Computed]
        public function eventDateLinks(): array
        {
            $today = UserDateTimeFormatter::userNow()->startOfDay();
            $nextWeekStart = $today->copy()->startOfWeek()->addWeek();
            $thisMonthStart = $today->copy()->startOfMonth();
            $nextMonthStart = $thisMonthStart->copy()->addMonth();

            if ($today->isSaturday()) {
                $weekendStart = $today;
                $weekendEnd = $today->copy()->next(Carbon::SUNDAY);
            } elseif ($today->isSunday()) {
                $weekendStart = $today;
                $weekendEnd = $today;
            } else {
                $weekendStart = $today->copy()->next(Carbon::SATURDAY);
                $weekendEnd = $weekendStart->copy()->next(Carbon::SUNDAY);
            }

            return [
                'today' => $this->dateRangeEventIndexQuery($today, $today),
                'malam_ini' => $this->dateRangeEventIndexQuery($today, $today),
                'tomorrow' => $this->dateRangeEventIndexQuery($today->copy()->addDay(), $today->copy()->addDay()),
                'this_week' => $this->dateRangeEventIndexQuery($today->copy()->startOfWeek(), $today->copy()->endOfWeek()),
                'weekend' => $this->dateRangeEventIndexQuery($weekendStart, $weekendEnd),
                'this_month' => $this->dateRangeEventIndexQuery($thisMonthStart, $today->copy()->endOfMonth()),
                'next_week' => $this->dateRangeEventIndexQuery($nextWeekStart, $nextWeekStart->copy()->endOfWeek()),
                'next_month' => $this->dateRangeEventIndexQuery($nextMonthStart, $nextMonthStart->copy()->endOfMonth()),
            ];
        }

        /**
         * @return array{starts_after: string, starts_before: string, time_scope: string}
         */
        private function dateRangeEventIndexQuery(CarbonInterface $startDate, CarbonInterface $endDate): array
        {
            return [
                'starts_after' => $startDate->toDateString(),
                'starts_before' => $endDate->toDateString(),
                'time_scope' => 'all',
            ];
        }
    };
?>

@section('title', __('ilmu360° - Cari Kuliah & Majlis Ilmu di Malaysia'))
@section('meta_description', __('Platform terbesar untuk mencari kuliah, ceramah, tazkirah, dan majlis ilmu di seluruh Malaysia. Cari yang berdekatan dengan anda.'))
@section('og_url', route('home'))
@section('og_image', asset('images/home/hero-mosque-v4.png'))
@section('og_image_alt', __('ilmu360°'))
@section('og_image_width', '1891')
@section('og_image_height', '831')

@php
    $eventDateLinks = $this->eventDateLinks();
    $quickFilters = [
        ['key' => 'today', 'label' => __('Hari ini')],
        ['key' => 'tomorrow', 'label' => __('Esok')],
        ['key' => 'this_week', 'label' => __('Minggu ini')],
        ['key' => 'weekend', 'label' => __('Hujung minggu')],
        ['key' => 'next_week', 'label' => __('Minggu depan')],
        ['key' => 'this_month', 'label' => __('Bulan ini')],
        ['key' => 'next_month', 'label' => __('Bulan depan')],
        ['key' => 'malam_ini', 'label' => __('Malam Ini')],
    ];
@endphp

<div
    class="bg-[#f8f7f1] text-[#102c25]"
    x-data="{
        searchType: 'majlis',
        activeQuickFilters: [],
        searchTargets: {
            majlis: @js(route('events.index')),
            institusi: @js(route('institutions.index')),
            penceramah: @js(route('persons.index')),
        },
        searchPlaceholders: {
            majlis: @js(__('Cari majlis...')),
            institusi: @js(__('Cari institusi...')),
            penceramah: @js(__('Cari penceramah...')),
        },
        selectHomeQuickFilter({ quickFilterKey }) {
            this.activeQuickFilters = [quickFilterKey];
            this.$dispatch('home-quick-filter-selected', { quickFilterKey });
        },
    }"
    x-on:home-quick-filter-state-updated.window="activeQuickFilters = $event.detail.activeQuickFilters ?? []"
>
    {{-- Hero --}}
    <section class="relative isolate min-h-[43rem] overflow-hidden bg-[#09251c] sm:min-h-[48rem] lg:min-h-[43rem]">
        <img src="{{ asset('images/home/hero-mosque-v4.png') }}" alt="" class="absolute inset-0 h-full w-full object-cover object-[74%_center]" fetchpriority="high">
        <div class="absolute inset-0 bg-[linear-gradient(90deg,rgba(4,20,15,0.96)_0%,rgba(4,20,15,0.86)_26%,rgba(4,20,15,0.62)_44%,rgba(4,20,15,0.3)_58%,rgba(4,20,15,0.06)_74%,rgba(4,20,15,0.18)_100%)]"></div>
        <div class="absolute inset-0 bg-[linear-gradient(0deg,rgba(3,24,17,0.45),transparent_45%,rgba(3,24,17,0.08))]"></div>
        <div class="absolute inset-0 bg-[linear-gradient(180deg,rgba(3,24,17,0.55)_0%,rgba(3,24,17,0.22)_16%,transparent_32%)]"></div>

        <div class="relative z-10 mx-auto flex min-h-[43rem] max-w-[120rem] items-end px-5 pb-12 pt-28 sm:min-h-[48rem] sm:px-8 sm:pb-16 lg:min-h-[43rem] lg:px-14 lg:pb-14 xl:px-16">
            <div class="w-full max-w-[48rem]">
                <h1 class="max-w-[48rem] font-serif text-[clamp(1.5rem,7vw,4rem)] font-semibold leading-[0.96] tracking-[-0.055em] text-white">
                    {{ __('Dekat dengan') }} <span class="text-[#e7bb57]">{{ __('Ilmu,') }}</span><br>
                    {{ __('Hidup Lebih Bermakna.') }}
                </h1>
                <p class="mt-5 max-w-xl text-sm leading-6 text-white/85 sm:text-base sm:leading-7">{{ __('Cari kuliah, tazkirah, kelas, dan majlis ilmu di seluruh Malaysia. Temui tempat, topik dan penceramah yang memberi makna.') }}</p>

                <fieldset class="mt-7 max-w-3xl">
                    <legend class="mb-2 text-[0.65rem] font-bold uppercase tracking-[0.18em] text-white/75">{{ __('Cari dalam') }}</legend>
                    <div class="flex flex-wrap gap-2">
                        @foreach([
                            'majlis' => __('Majlis'),
                            'institusi' => __('Institusi'),
                            'penceramah' => __('Penceramah'),
                        ] as $value => $label)
                            <label class="cursor-pointer">
                                <input
                                    type="radio"
                                    name="search_category"
                                    value="{{ $value }}"
                                    x-model="searchType"
                                    @checked($value === 'majlis')
                                    data-signal-change-event="search.category_selected"
                                    data-signal-category="search"
                                    data-signal-component="home_hero"
                                    data-signal-control="search_category"
                                    class="peer sr-only"
                                >
                                <span
                                    :class="searchType === @js($value) ? 'border-[#f5d98a] bg-[#f5d98a] text-[#17342a] shadow-lg shadow-black/15' : 'border-white/30 bg-black/20 text-white/90 hover:border-white/60 hover:bg-black/35'"
                                    class="inline-flex min-h-10 items-center rounded-full border px-4 text-xs font-bold backdrop-blur-sm transition sm:text-sm"
                                >{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <form x-bind:action="searchTargets[searchType]" method="GET" class="mt-4 max-w-3xl rounded-2xl border border-white/40 bg-white p-1.5 shadow-2xl shadow-black/30 sm:flex sm:items-center" data-signal-submit-event="search.submitted" data-signal-category="search" data-signal-component="home_hero" data-signal-control="hero_search">
                    <label class="flex min-h-12 flex-1 items-center gap-3 rounded-xl px-3 text-sm text-[#667b7a] sm:px-4">
                        <svg class="h-5 w-5 shrink-0 text-[#174f46]" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><circle cx="11" cy="11" r="6.75" stroke-width="1.8"/><path stroke-linecap="round" stroke-width="1.8" d="m16 16 4.5 4.5"/></svg>
                        <input type="search" name="search" x-bind:placeholder="searchPlaceholders[searchType]" aria-label="{{ __('Carian') }}" class="min-w-0 flex-1 border-0 bg-transparent text-sm text-[#153c35] outline-none placeholder:text-[#8c9a97] focus:ring-0">
                    </label>
                    <button type="submit" class="mt-1 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-[#087f59] px-7 text-sm font-bold text-white transition hover:bg-[#056747] sm:mt-0 sm:w-auto">{{ __('Cari') }}<span aria-hidden="true">→</span></button>
                </form>

                <div x-show="searchType === 'majlis'" x-cloak class="mt-5 flex max-w-4xl flex-wrap gap-2">
                    @foreach($quickFilters as $filter)
                        <button
                            type="button"
                            x-on:click="selectHomeQuickFilter({ quickFilterKey: @js($filter['key']) })"
                            :class="activeQuickFilters.includes(@js($filter['key'])) ? 'border-[#e7bb57] bg-[#e7bb57] text-[#183128]' : 'border-white/25 bg-black/20 text-white/90 hover:border-[#e7bb57] hover:bg-[#e7bb57] hover:text-[#183128]'"
                            :aria-pressed="activeQuickFilters.includes(@js($filter['key']))"
                            data-signal-event="filter.quick_selected"
                            data-signal-category="filter"
                            data-signal-component="home_hero"
                            data-signal-control="{{ $filter['key'] }}"
                            class="inline-flex items-center rounded-full border px-3.5 py-2 text-xs font-semibold backdrop-blur-sm transition"
                        >
                            {{ $filter['label'] }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- Geography filter --}}
    <section x-show="searchType === 'majlis'" x-cloak class="relative z-20 mx-auto max-w-7xl px-5 py-4 sm:px-6 lg:px-8">
        <livewire:components.event-filters :quick-filter-ranges="$eventDateLinks" :show-nearby-button="false" />
    </section>

    {{-- Featured events --}}
    <section class="bg-[#f8f7f1] py-12 sm:py-16 lg:py-20">
        <div class="mx-auto grid max-w-[120rem] gap-8 px-5 sm:px-8 lg:grid-cols-1 lg:px-14 xl:px-16">
            <div>
                <div class="flex items-end justify-between gap-4"><div><p class="flex items-center gap-2 text-[0.68rem] font-bold uppercase tracking-[0.2em] text-[#087f59]"><span class="h-2 w-2 rounded-full bg-[#087f59]"></span>{{ __('Majlis Akan Datang') }}</p><h2 class="mt-2 font-heading text-3xl font-bold tracking-tight text-[#112c26] sm:text-4xl">{{ __('Pilihan Majlis Minggu Ini') }}</h2></div><a href="{{ route('events.index') }}" wire:navigate class="hidden items-center gap-2 text-xs font-bold text-[#087f59] sm:inline-flex">{{ __('Lihat semua majlis') }} <span aria-hidden="true">→</span></a></div>
                @if($this->featuredEvents->isNotEmpty())
                    <div class="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach($this->featuredEvents as $event)
                            @php
                                $eventSpeakers = $event->persons->unique('id');
                                $eventSpeakerNames = $eventSpeakers->pluck('formatted_name')->filter()->join(', ');
                                $speakerAvatarItems = $eventSpeakers
                                    ->map(fn (\App\Models\Person $person): array => [
                                        'name' => trim((string) ($person->formatted_name !== '' ? $person->formatted_name : $person->name)),
                                        'url' => $person->public_avatar_url,
                                    ])
                                    ->filter(fn (array $avatar): bool => $avatar['name'] !== '' && $avatar['url'] !== '')
                                    ->values();
                                $speakerAvatarOverflow = max(0, $speakerAvatarItems->count() - 3);
                                $speakerAvatarItems = $speakerAvatarItems->take(3)->values();
                                $eventDate = $event->starts_at ? UserDateTimeFormatter::format($event->starts_at, 'd') : '--';
                                $eventMonth = $event->starts_at ? UserDateTimeFormatter::translatedFormat($event->starts_at, 'M') : '';
                                $eventTime = $event->starts_at ? $event->timing_display : '';
                                $viewerTimezone = UserDateTimeFormatter::resolveTimezone();

                                if ($eventTime !== ''
                                    && $event->starts_at instanceof CarbonInterface
                                    && $event->ends_at instanceof CarbonInterface
                                    && $event->ends_at->gt($event->starts_at)
                                    && $event->ends_at->copy()->timezone($viewerTimezone)->isSameDay($event->starts_at->copy()->timezone($viewerTimezone))
                                ) {
                                    $eventTime .= ' — '.UserDateTimeFormatter::format($event->ends_at, 'g:i A');
                                }
                                $eventLocation = $event->institution?->name ?? __('Seluruh Malaysia');
                                $eventLocationSubtitle = AddressHierarchyFormatter::format($event->institution?->primaryAddress());
                                $isSaved = in_array((string) $event->getKey(), $this->savedEventIds, true);
                            @endphp
                            <article wire:key="home-featured-{{ $event->id }}" class="group relative flex flex-col overflow-hidden rounded-2xl border border-[#e5e8df] bg-white shadow-[0_12px_30px_-18px_rgba(24,53,43,0.35)] transition hover:-translate-y-1 hover:shadow-[0_20px_38px_-18px_rgba(24,53,43,0.45)]"><a href="{{ route('events.show', $event) }}" wire:navigate class="flex flex-1 flex-col"><div class="relative aspect-[1.35] overflow-hidden bg-[#dfe9df]"><img src="{{ $event->card_image_url }}" alt="{{ $event->title }}" loading="lazy" class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.02]"><span class="absolute left-3 top-3 rounded-full bg-[#087f59] px-2.5 py-1 text-[0.63rem] font-bold text-white shadow">{{ $event->eventType?->name ?? __('Kuliah') }}</span></div><div class="px-4 pt-4"><div class="flex items-center gap-3"><div class="flex h-14 w-12 shrink-0 flex-col items-center justify-center rounded-xl bg-[#f2f8f1] text-[#087f59]"><span class="text-[0.58rem] font-bold uppercase tracking-widest">{{ $eventMonth }}</span><span class="font-heading text-xl font-bold leading-none">{{ $eventDate }}</span></div><div class="min-w-0"><h3 class="line-clamp-2 font-heading text-base font-bold leading-tight text-[#142f28]">{{ $event->title }}</h3></div></div><div class="mt-4 space-y-2 text-sm text-[#56716a]"><p class="flex items-start gap-2"><svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-[#087f59]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-width="1.8" d="M12 21s7-5.2 7-11a7 7 0 1 0-14 0c0 5.8 7 11 7 11Z"/><circle cx="12" cy="10" r="2.2" stroke-width="1.8"/></svg><span class="line-clamp-2">{{ $eventLocation }}{{ $eventLocationSubtitle !== '' ? ', '.$eventLocationSubtitle : '' }}</span></p><p class="flex items-center gap-2">@if($speakerAvatarItems->isNotEmpty())<span class="flex shrink-0 -space-x-2" data-testid="homepage-featured-card-speaker-avatars" aria-label="{{ __('Penceramah') }}">@foreach($speakerAvatarItems as $avatar)<img src="{{ $avatar['url'] }}" alt="{{ $avatar['name'] }}" title="{{ $avatar['name'] }}" loading="lazy" class="h-9 w-9 rounded-full object-cover ring-2 ring-white shadow-sm" />@endforeach@if($speakerAvatarOverflow > 0)<span class="flex h-9 w-9 items-center justify-center rounded-full bg-[#f2f8f1] text-xs font-bold text-[#087f59] ring-2 ring-white shadow-sm">+{{ $speakerAvatarOverflow }}</span>@endif</span>@else<svg class="h-5 w-5 shrink-0 text-[#087f59]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 1 1 7.5 0ZM4.5 20.25a8.25 8.25 0 1 1 15 0" /></svg>@endif<span data-testid="homepage-featured-card-speakers" class="line-clamp-2">{{ $eventSpeakerNames !== '' ? $eventSpeakerNames : __('Penceramah jemputan') }}</span></p></div></div></a><div class="mt-auto flex items-center justify-between gap-3 border-t border-slate-100 px-4 pb-4 pt-4"><p class="flex min-w-0 items-center gap-2"><svg class="h-3.5 w-3.5 shrink-0 text-[#087f59]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="12" cy="12" r="8" stroke-width="1.8"/><path stroke-linecap="round" stroke-width="1.8" d="M12 7v5l3 2"/></svg><span class="truncate font-semibold text-[#087f59]">{{ $eventTime }}</span></p><button type="button" wire:click.stop.prevent="toggleSave('{{ $event->getKey() }}')" wire:loading.attr="disabled" data-save-icon="event" data-save-state="{{ $isSaved ? 'saved' : 'unsaved' }}" aria-label="{{ $isSaved ? __('Disimpan') : __('Simpan') }}" aria-pressed="{{ $isSaved ? 'true' : 'false' }}" title="{{ $isSaved ? __('Disimpan') : __('Simpan') }}" data-signal-event="engagement.event_save_clicked" data-signal-category="engagement" data-signal-component="home_featured_events" data-signal-control="save" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" data-signal-props='@json(['currently_saved' => $isSaved])' class="grid h-9 w-9 shrink-0 place-items-center rounded-xl border transition-colors duration-200 disabled:cursor-wait disabled:opacity-60 {{ $isSaved ? 'border-[#087f59] bg-[#087f59] text-white' : 'border-slate-200 bg-white text-slate-400 group-hover:border-emerald-200 group-hover:text-emerald-700' }}">@if($isSaved)<svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6.75 4.5A2.25 2.25 0 0 1 9 2.25h6a2.25 2.25 0 0 1 2.25 2.25V21L12 17.25 6.75 21V4.5Z" /></svg>@else<svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 4.5A2.25 2.25 0 0 1 9 2.25h6a2.25 2.25 0 0 1 2.25 2.25V21L12 17.25 6.75 21V4.5Z" /></svg>@endif</button></div></article>
                        @endforeach
                    </div>
                @else
                    <div class="mt-7 rounded-2xl border border-dashed border-[#cad9cf] bg-white/70 p-10 text-center text-sm text-[#5b716d]">{{ __('Majlis akan datang akan dipaparkan di sini.') }}</div>
                @endif
            </div>
            {{-- Homepage Google Maps panel temporarily hidden.
            <aside class="relative isolate min-h-[22rem] overflow-hidden rounded-[1.75rem] border border-[#dce8dc] bg-[#deefe3] shadow-[0_20px_45px_-28px_rgba(20,69,52,0.5)]">
                @if($featuredMapLocation)
                    <iframe
                        src="{{ $featuredMapLocation['embed_url'] }}"
                        class="absolute inset-0 h-full w-full border-0"
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                        allowfullscreen
                        title="{{ __('Google Maps location for :event', ['event' => $featuredMapLocation['event']->title]) }}"
                    ></iframe>
                    <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-[#09251c]/35 via-transparent to-white/10"></div>
                @else
                    <div class="absolute inset-0 bg-[radial-gradient(circle_at_55%_45%,rgba(255,255,255,0.8),transparent_38%)]"></div>
                @endif

                <div class="relative z-10 flex h-full min-h-[22rem] flex-col justify-between p-5 sm:p-6">
                    <div class="w-fit rounded-2xl border border-white/80 bg-white/95 px-4 py-3 shadow-lg shadow-[#759a81]/20 backdrop-blur-sm">
                        <p class="flex items-center gap-2 text-[0.65rem] font-bold uppercase tracking-[0.2em] text-[#087f59]"><span class="h-2 w-2 rounded-full bg-[#087f59]"></span>{{ __('Lokasi Majlis') }}</p>
                        <h2 class="mt-1 font-serif text-2xl font-semibold italic leading-none text-[#12342a]">{{ __('Majlis Berdekatan') }}</h2>
                    </div>

                    @if($featuredMapLocation)
                        <div class="rounded-2xl border border-white/80 bg-white/95 p-3 shadow-lg shadow-[#759a81]/20 backdrop-blur-sm">
                            <div class="flex items-center gap-3">
                                @if($featuredMapLocation['event']->card_image_url)
                                    <img src="{{ $featuredMapLocation['event']->card_image_url }}" alt="" loading="lazy" class="h-14 w-14 shrink-0 rounded-xl object-cover">
                                @endif
                                <div class="min-w-0 flex-1">
                                    <p class="line-clamp-2 text-xs font-bold text-[#19392f]">{{ $featuredMapLocation['event']->title }}</p>
                                    <p class="mt-1 truncate text-[0.68rem] font-semibold text-[#687d76]">{{ $featuredMapLocation['location'] }}</p>
                                    <p class="truncate text-[0.65rem] text-[#687d76]">{{ $featuredMapLocation['address'] }}</p>
                                </div>
                            </div>
                            <a
                                href="{{ $featuredMapLocation['directions_url'] }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                data-signal-event="navigation.external_link_clicked"
                                data-signal-category="navigation"
                                data-signal-component="home_nearby_map"
                                data-signal-control="google_maps"
                                data-signal-entity-type="event"
                                data-signal-entity-id="{{ $featuredMapLocation['event']->id }}"
                                class="mt-3 inline-flex items-center gap-2 text-[0.7rem] font-bold text-[#087f59] hover:text-[#056747]"
                            >{{ __('Buka di Google Maps') }} <span aria-hidden="true">↗</span></a>
                        </div>
                    @else
                        <div class="rounded-2xl border border-white/80 bg-white/95 p-4 shadow-lg shadow-[#759a81]/20 backdrop-blur-sm">
                            <p class="text-sm font-semibold text-[#19392f]">{{ __('Peta akan dipaparkan apabila lokasi fizikal majlis tersedia.') }}</p>
                            <a href="{{ route('events.index') }}" wire:navigate data-signal-event="navigation.internal_link_clicked" data-signal-category="navigation" data-signal-component="home_nearby_map" data-signal-control="browse_events" class="mt-3 inline-flex items-center gap-2 text-[0.7rem] font-bold text-[#087f59] hover:text-[#056747]">{{ __('Lihat semua majlis') }} <span aria-hidden="true">→</span></a>
                        </div>
                    @endif
                </div>
            </aside>
            --}}
        </div>
    </section>

    {{-- Speakers --}}
    <section class="relative isolate overflow-hidden bg-[#0b3027] text-white">
        <img src="{{ asset('images/home/speaker-feature-v1.png') }}" alt="" aria-hidden="true" class="absolute inset-0 h-full w-full object-cover object-[center_58%]">
        <div class="absolute inset-0 bg-[linear-gradient(90deg,rgba(4,24,19,0.28)_0%,rgba(4,24,19,0.12)_30%,transparent_58%,rgba(255,247,228,0.12)_100%)]"></div>
        <div class="absolute inset-0 bg-[linear-gradient(0deg,rgba(4,25,19,0.2),transparent_46%)]"></div>

        <div class="relative mx-auto grid max-w-[120rem] gap-6 px-5 py-8 sm:px-8 sm:py-9 lg:grid-cols-[17rem_minmax(0,1fr)_8rem] lg:items-center lg:gap-4 lg:px-14 lg:py-8 xl:px-16">
            <div>
                <p class="flex items-center gap-2 text-[0.62rem] font-bold uppercase tracking-[0.12em] text-white/95 sm:text-[0.68rem]">
                    <span class="h-2 w-2 rounded-full bg-[#54b77d]"></span>{{ __('Ikuti penceramah kegemaran anda') }}
                </p>
                <h2 class="mt-3 max-w-[14rem] font-serif text-[2rem] font-semibold leading-[0.94] tracking-[-0.025em] sm:text-[2.25rem]">
                    {{ __('Tokoh Ilmu,') }}<br>{{ __('Lebih Dekat') }}
                </h2>
                <p class="mt-3 max-w-[15rem] text-xs leading-[1.5] text-white/90">
                    {{ __('Dapatkan notifikasi majlis terbaru daripada penceramah pilihan anda.') }}
                </p>
                <a href="{{ route('persons.index') }}" wire:navigate class="mt-4 inline-flex items-center gap-2 rounded-full bg-[#f1cd72] px-4 py-2.5 text-[0.68rem] font-bold text-[#16372d] shadow-lg shadow-black/20 transition hover:bg-[#ffe19a] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                    {{ __('Terokai Penceramah') }} <span aria-hidden="true">→</span>
                </a>
            </div>

            <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-5 sm:gap-3">
                @forelse($this->featuredPersons as $person)
                    <a href="{{ route('persons.show', $person) }}" wire:navigate wire:key="home-speaker-{{ $person->id }}" class="group relative aspect-[0.82] min-h-[10rem] overflow-hidden rounded-xl border border-white/35 bg-[#153d31]/55 shadow-lg shadow-black/15 sm:aspect-[0.58] sm:min-h-[10rem]">
                        <img src="{{ $person->public_avatar_url ?: $person->default_avatar_url }}" alt="{{ $person->formatted_name }}" loading="lazy" class="absolute inset-0 h-full w-full object-cover object-top opacity-95 transition duration-500 group-hover:scale-[1.03]">
                        <div class="absolute inset-0 bg-gradient-to-t from-[#05251d]/95 via-[#05251d]/10 to-transparent"></div>
                        <span class="absolute inset-x-2.5 bottom-2.5 line-clamp-2 text-[0.66rem] font-bold leading-tight text-white sm:text-xs">{{ $person->formatted_name }}</span>
                    </a>
                @empty
                    <div class="col-span-full rounded-xl border border-white/25 bg-[#153d31]/60 p-8 text-sm text-white/85">{{ __('Penceramah pilihan akan dipaparkan di sini.') }}</div>
                @endforelse
            </div>

            <p class="hidden max-w-[8rem] font-serif text-base italic leading-[1.2] text-[#4d5d4e] lg:block">“{{ __('Ilmu dikongsi, kebaikan dirasai.') }}”</p>
        </div>

        <div class="relative z-10 mx-auto max-w-[120rem] px-5 pb-6 sm:px-8 lg:px-14 xl:px-16">
            <livewire:home.stats />
        </div>
    </section>

    {{-- Community CTA --}}
    <section class="relative isolate min-h-[28rem] overflow-hidden bg-[#071b27] text-white sm:min-h-[24rem] lg:min-h-[19rem]">
        <img
            src="{{ asset('images/home/community-lake-cta-v2.png') }}"
            alt=""
            loading="lazy"
            class="absolute inset-0 h-full w-full object-cover object-[73%_center] md:object-center"
        >
        <div class="absolute inset-0 bg-[linear-gradient(90deg,rgba(3,16,29,0.4)_0%,rgba(3,16,29,0.22)_38%,rgba(3,16,29,0.04)_72%,rgba(3,16,29,0.08)_100%)]"></div>
        <div class="absolute inset-0 bg-[linear-gradient(0deg,rgba(2,13,24,0.24)_0%,rgba(2,13,24,0)_74%)]"></div>

        <div class="relative mx-auto grid min-h-[28rem] max-w-[120rem] gap-9 px-5 py-9 sm:min-h-[24rem] sm:px-8 sm:py-10 md:grid-cols-[minmax(0,1.15fr)_minmax(19rem,0.85fr)] md:items-stretch lg:min-h-[19rem] lg:px-14 lg:py-8 xl:px-16">
            <div class="flex max-w-2xl flex-col justify-end">
                <h2 class="font-serif text-[2.7rem] font-normal italic leading-[0.98] tracking-[-0.025em] text-[#f4dfa0] sm:text-5xl">
                    {{ __('Ilmu Menghubungkan Kita') }}
                </h2>
                <p class="mt-3 max-w-xl text-sm leading-6 text-white/90 sm:text-base">
                    {{ __('Sertai komuniti ilmu360° dan jangan terlepas majlis ilmu berhampiran anda.') }}
                </p>
                <div class="mt-5 flex flex-wrap gap-3">
                    <a href="{{ route('register') }}" class="inline-flex min-h-11 items-center gap-3 rounded-full bg-[#f1cb64] px-5 py-3 text-xs font-bold text-[#15372d] shadow-lg shadow-black/20 transition hover:bg-[#ffe39b] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#f4dfa0]">
                        {{ __('Daftar Sekarang') }}
                        <span aria-hidden="true">→</span>
                    </a>
                    <a href="{{ route('about') }}" wire:navigate class="inline-flex min-h-11 items-center gap-3 rounded-full border border-white/80 bg-black/10 px-5 py-3 text-xs font-bold text-white shadow-lg shadow-black/10 backdrop-blur-sm transition hover:bg-white/15 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#f4dfa0]">
                        {{ __('Ketahui Lebih Lanjut') }}
                        <span aria-hidden="true">→</span>
                    </a>
                </div>
            </div>

            <div class="flex flex-col justify-between gap-8 md:pb-1">
                <p class="hidden max-w-[12rem] self-end text-right font-serif text-[1.35rem] font-semibold italic leading-[1.1] text-white drop-shadow-[0_2px_7px_rgba(2,13,24,0.95)] md:block">
                    {{ __('Ilmu diamalkan, ummah diperkasakan.') }}
                </p>

                <div class="grid grid-cols-2 gap-x-3 gap-y-5 text-center sm:grid-cols-4 sm:gap-x-5 lg:gap-x-6">
                    @foreach([
                        ['icon' => 'mosque', 'label' => __('Lebih Dekat dengan Masjid')],
                        ['icon' => 'book', 'label' => __('Lebih Dekat dengan Ulama')],
                        ['icon' => 'community', 'label' => __('Lebih Dekat dengan Komuniti')],
                        ['icon' => 'heart', 'label' => __('Lebih Dekat dengan Allah')],
                    ] as $benefit)
                        <div class="flex min-w-0 flex-col items-center gap-2 text-[0.68rem] leading-4 text-white/90">
                            <span class="flex h-11 w-11 items-center justify-center rounded-full border border-white/25 bg-white/10 text-white shadow-lg shadow-black/15 backdrop-blur-sm">
                                @if($benefit['icon'] === 'mosque')
                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M3 20.5h18M5.5 20.5v-8.2h13v8.2M7.5 12.3V8.8L12 5.2l4.5 3.6v3.5M10 20.5v-5h4v5M12 5.2V3.4M3 12.3h18" />
                                    </svg>
                                @elseif($benefit['icon'] === 'book')
                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M12 6.2c-2.1-1.6-5.2-1.9-8-.6v13.2c2.8-1.3 5.9-1 8 .6m0-13.2c2.1-1.6 5.2-1.9 8-.6v13.2c-2.8-1.3-5.9-1-8 .6m0-13.2v13.2" />
                                    </svg>
                                @elseif($benefit['icon'] === 'community')
                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                        <circle cx="9" cy="8" r="3" stroke-width="1.6" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M3.5 20v-1.5a5.5 5.5 0 0 1 11 0V20M16 5.6a3.2 3.2 0 0 1 0 6.2M17.3 15a4.8 4.8 0 0 1 3.2 4.5V20" />
                                    </svg>
                                @else
                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M20.8 8.8c0 5.2-8.8 10.6-8.8 10.6S3.2 14 3.2 8.8A4.5 4.5 0 0 1 12 6.9a4.5 4.5 0 0 1 8.8 1.9Z" />
                                    </svg>
                                @endif
                            </span>
                            <span>{{ $benefit['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
</div>
