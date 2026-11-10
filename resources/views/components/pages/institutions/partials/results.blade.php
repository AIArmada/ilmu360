@php
    $institutions = $this->institutions;
    $institutionLoadingTarget = 'search,country,state,city,locality,district,subdivision,areas,followed_only,toggleFollowedOnly,clearSearch,clearFilters,gotoPage,setPage,nextPage,previousPage,selectGroupedSubdivisionLocality,__dispatch';
    $formatInstitutionLocation = static function ($addressModel): string {
        $parts = \App\Support\Location\AddressHierarchyFormatter::parts($addressModel);

        return $parts === [] ? '-' : implode(', ', $parts);
    };
    $activeSearch = is_string($this->search) ? trim($this->search) : '';
    $institutionTotal = $institutions->total();
@endphp

<div
    id="institution-results"
    class="min-h-[32rem]"
    role="region"
    aria-label="{{ __('Institution list') }}"
    data-search-query="{{ $activeSearch }}"
    data-search-total="{{ $institutionTotal }}"
>
    {{-- Searching indicator lives outside the dimmed content region so the
        status itself is never dimmed, and the inner flex block is used
        because wire:loading sets inline display:inline-block on the
        directive element, which would override flex layout. --}}
    <div
        wire:loading.delay.short
        wire:target="search"
        role="status"
        aria-live="polite"
    >
        <div class="mb-4 flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm font-semibold text-emerald-800">
            <svg class="size-4 animate-spin motion-reduce:animate-none" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z" />
            </svg>
            <span>{{ __('Searching…') }}</span>
        </div>
    </div>

    <div class="mb-6 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="font-heading text-2xl font-bold tracking-tight text-emerald-950 sm:text-3xl">
                @if($activeSearch !== '')
                    {{ __('Search results for “:search”', ['search' => $activeSearch]) }}
                @else
                    {{ __('Institution directory') }}
                @endif
            </h2>
            <p class="mt-2 text-sm font-semibold text-slate-600" aria-live="polite">
                {{ trans_choice(':count institution found|:count institutions found', $institutionTotal, ['count' => number_format($institutionTotal)]) }}
            </p>
        </div>
    </div>

    {{-- Old results stay visible and dimmed while the new query resolves;
        only the latest query morphs thanks to the stale-response guard. --}}
    <div
        class="transition-opacity"
        wire:loading.class="opacity-60"
        wire:loading.attr="aria-busy"
        wire:target="{{ $institutionLoadingTarget }}"
    >
    @if($institutions->isEmpty())
        <div class="text-center py-24 rounded-3xl bg-slate-50/50 border border-dashed border-slate-200">
            <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-white text-slate-300 shadow-sm mb-6">
                <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
            </div>
            <h3 class="text-xl font-bold text-slate-900">{{ $this->followed_only ? __('No followed institutions yet') : __('No institutions found') }}</h3>
            <p class="text-slate-500 mt-2 max-w-md mx-auto">{{ $this->followed_only ? __('Follow an institution to see it here.') : __('We couldn\'t find any institutions matching your search.') }}</p>
                <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                    <button type="button" x-on:click="$dispatch('institution-clear-filters')" class="font-semibold text-emerald-600 hover:text-emerald-700">
                        {{ __('Clear Filters') }} &rarr;
                    </button>
                </div>
        </div>
    @else
        <div class="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
            @foreach($institutions as $institution)
                @php
                    $cardInstitutionImageUrl = $institution->public_cover_url !== ''
                        ? $institution->public_cover_url
                        : ($institution->public_logo_url !== '' ? $institution->public_logo_url : null);
                    $isFollowing = in_array((string) $institution->getKey(), $this->followingInstitutionIds, true);
                @endphp
                <article wire:key="institution-{{ $institution->id }}" class="living-majlis-card group relative flex h-full flex-col overflow-hidden rounded-[1.5rem] border border-emerald-200 transition-[border-color,background-color,box-shadow,transform] duration-300 hover:-translate-y-1.5 hover:border-amber-200 hover:bg-amber-100 hover:shadow-[0_22px_50px_-28px_rgba(217,165,20,0.40)]">
                    <a href="{{ route('institutions.show', $institution) }}" wire:navigate class="flex flex-1 flex-col after:absolute after:inset-0">
                    <!-- Banner Area (16:9, cover-first) -->
                    <div class="institution-card-media aspect-video bg-slate-50 relative overflow-hidden">
                        @if($cardInstitutionImageUrl)
                            <img src="{{ $cardInstitutionImageUrl }}" alt="{{ $institution->name }}" class="h-full w-full object-cover" loading="lazy">
                            <div class="absolute inset-0 bg-gradient-to-t from-slate-900/50 via-slate-900/15 to-transparent"></div>
                        @else
                            <img
                                srcset="{{ asset('images/placeholders/institution-v2.webp') }}" src="{{ asset('images/placeholders/institution-v2.png') }}"
                                alt=""
                                aria-hidden="true"
                                class="h-full w-full object-cover"
                                loading="lazy"
                            >
                            <div class="absolute inset-0 bg-gradient-to-t from-emerald-950/35 via-transparent to-transparent"></div>
                        @endif
                    </div>

                    <div class="p-6 pb-0 pt-6 relative flex-1 flex flex-col">
                        <h3 class="font-heading text-lg font-bold text-slate-900 group-hover:text-emerald-700 transition-colors mb-2 leading-tight">
                            {{ $institution->name }}
                            @if($institution->status === \App\Shared\Directory\Enums\InstitutionStatus::Verified)
                                <x-ui.verified-badge class="text-emerald-600" data-testid="verified-badge" />
                            @endif
                        </h3>

                        @php
                            $address = $institution->primaryAddress();
                            $locationDisplay = $formatInstitutionLocation($address);
                        @endphp
                        <p class="text-sm text-slate-600 flex items-start gap-1.5 mb-4 font-medium">
                            <flux:icon.map-pin class="mt-0.5 size-4 shrink-0 text-emerald-500" />
                            <span class="line-clamp-2">{{ $locationDisplay }}</span>
                        </p>

                        @php
                            $nextEventStartsAt = filled($institution->next_event_starts_at)
                                ? \Carbon\CarbonImmutable::parse((string) $institution->next_event_starts_at, 'UTC')
                                : null;
                        @endphp

                    </div>
                    </a>
                    @if($nextEventStartsAt instanceof \Carbon\CarbonImmutable && filled($institution->next_event_slug) && filled($institution->next_event_title))
                        <a
                            data-next-event
                            href="{{ route('events.show', ['event' => $institution->next_event_slug]) }}"
                            wire:navigate
                            class="group/next relative z-10 mx-6 mt-4 block min-w-0 border-t border-slate-100 pb-4 pt-4 transition-colors duration-200 group-hover:border-amber-200 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/15"
                        >
                            <span class="min-w-0">
                                <span class="block text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">{{ __('Next event') }}</span>
                                <span class="mt-1 block truncate text-[11px] font-semibold text-slate-700 transition-colors group-hover/next:font-bold group-hover/next:text-emerald-800 sm:text-xs">
                                    {{ \App\Platform\Time\UserDateTimeFormatter::translatedFormat($nextEventStartsAt, 'j M') }}
                                    <span class="text-slate-300" aria-hidden="true">·</span>
                                    {{ $institution->next_event_title }}
                                </span>
                            </span>
                        </a>
                    @endif
                    <div class="flex items-center justify-between gap-3 border-t border-slate-100 px-6 pb-6 pt-5 group-hover:border-amber-200">
                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                            <flux:icon.calendar class="size-3.5 text-emerald-500" />
                            {{ $institution->events_count }} {{ __('Upcoming Events') }}
                        </span>
                        <livewire:components.directory-card-follow
                            subject-type="institution"
                            :subject-id="(string) $institution->getKey()"
                            :is-following="$isFollowing"
                            :refresh-listing="(bool) $this->followed_only"
                            :return-url="route('institutions.index', absolute: false)"
                            :key="'directory-follow-institution-'.$institution->getKey().'-'.(int) $isFollowing.'-'.(int) $this->followed_only.'-'.md5(route('institutions.index', absolute: false))"
                        />
                    </div>
                </article>
            @endforeach
        </div>

                <div class="mt-16">
                    {{ $institutions->withQueryString()->links('vendor.livewire.directory-pagination', data: ['scrollTo' => '#institution-results']) }}
                </div>

    @endif
    </div>
</div>
