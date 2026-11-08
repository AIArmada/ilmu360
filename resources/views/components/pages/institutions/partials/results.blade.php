@php
    $institutions = $this->institutions;
    $institutionLoadingTarget = 'search,country,state,city,locality,district,subdivision,areas,followed_only,toggleFollowedOnly,clearSearch,clearFilters,gotoPage,setPage,nextPage,previousPage,selectGroupedSubdivisionLocality,__dispatch';
    $formatInstitutionLocation = static function ($addressModel): string {
        $parts = \App\Support\Location\AddressHierarchyFormatter::parts($addressModel);

        return $parts === [] ? '-' : implode(', ', $parts);
    };
@endphp

<div id="institution-results" class="min-h-[32rem]">
    <div wire:loading.delay.short wire:target="{{ $institutionLoadingTarget }}" class="w-full">
        <x-ui.skeleton.institution-card-grid />
    </div>

    <div wire:loading.remove wire:target="{{ $institutionLoadingTarget }}">
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
                                    @if($institution->status === \App\Enums\InstitutionStatus::Verified)
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
                                            {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($nextEventStartsAt, 'j M') }}
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
