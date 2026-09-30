@php
    $showNearbyButton ??= true;
    $activeFilterCount = $this->activeFilterCount();
    $hasActiveFilters = $activeFilterCount > 0;
    $lat = $this->lat;
@endphp

<section class="living-majlis-veil overflow-hidden rounded-[1.5rem] border shadow-[0_20px_50px_-35px_rgba(15,23,42,0.55)]">
            <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between md:p-5">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M6.75 12h10.5m-7.5 5.25h4.5" />
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">{{ __('Filters') }}</p>
                            @if($hasActiveFilters)
                                <span class="inline-flex min-w-6 items-center justify-center rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-800" aria-label="{{ $activeFilterCount }} {{ __('Filters') }}">{{ $activeFilterCount }}</span>
                            @endif
                        </div>
                        <p class="mt-1 text-sm text-slate-500">{{ __('Keputusan dikemas kini secara automatik.') }}</p>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-3">
                    <div wire:loading.delay.short
                        wire:target="filterData,setLocation,clearLocation,clearAllFilters,toggleSave"
                        class="hidden items-center gap-2 text-xs font-semibold text-emerald-700 sm:inline-flex">
                        <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke-width="4"></circle>
                            <path class="opacity-75" stroke-width="4" d="M22 12a10 10 0 0 0-10-10"></path>
                        </svg>
                        {{ __('Updating results...') }}
                    </div>
                    <button type="button" @click="filtersOpen = !filtersOpen"
                        :aria-expanded="filtersOpen"
                        aria-controls="majlis-filter-panel"
                        data-signal-event="filter.panel_toggled"
                        data-signal-category="filter"
                        data-signal-component="events_index_filters"
                        data-signal-control="filter_panel"
                        class="inline-flex h-11 items-center gap-2 rounded-xl bg-emerald-800 px-3.5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2">
                        <svg class="size-4 transition-transform duration-200" :class="filtersOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                        </svg>
                        {{ __('Filters') }}
                    </button>
                </div>
            </div>

            <div id="majlis-filter-panel" x-show="filtersOpen" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="border-t border-slate-100">
                <div class="p-4 md:p-5">
                    <div class="mb-5 flex flex-col gap-3 rounded-xl border border-emerald-100 bg-emerald-50/60 p-3.5 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <svg class="size-4 text-emerald-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-4.438 7-11a7 7 0 1 0-14 0c0 6.562 7 11 7 11Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5h.01" />
                                </svg>
                                <h2 class="font-heading text-sm font-bold text-emerald-950">{{ __('Lokasi') }}</h2>
                            </div>
                            @if($showNearbyButton)
                                <p class="mt-1 text-xs leading-5 text-slate-600">{{ __('Cari majlis berdekatan anda atau pilih lokasi tertentu.') }}</p>
                            @endif
                        </div>
                        @if($showNearbyButton || $lat)
                            <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                                @if($showNearbyButton)
                                    <button type="button" @click="locate" :disabled="locating"
                                        data-testid="near-me-button"
                                        data-signal-event="search.nearby_requested"
                                        data-signal-category="search"
                                        data-signal-component="events_index_filters"
                                        data-signal-control="near_me"
                                        class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-emerald-200 bg-white px-3.5 text-sm font-bold text-emerald-800 transition hover:border-emerald-300 hover:bg-emerald-50 disabled:cursor-wait disabled:opacity-70">
                                        <svg class="size-4" :class="locating ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                            <path x-show="! locating" stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-4.438 7-11a7 7 0 1 0-14 0c0 6.562 7 11 7 11Z" />
                                            <path x-show="! locating" stroke-linecap="round" stroke-linejoin="round" d="M12 10.5h.01" />
                                            <circle x-show="locating" class="opacity-25" cx="12" cy="12" r="10" stroke-width="4"></circle>
                                            <path x-show="locating" class="opacity-75" stroke-width="4" d="M22 12a10 10 0 0 0-10-10"></path>
                                        </svg>
                                        <span x-text="locating ? '{{ __('Locating...') }}' : '{{ __('Dekat saya') }}'"></span>
                                    </button>
                                @endif
                                @if($lat)
                                    <label
                                        data-testid="nearby-radius-inline"
                                        data-signal-control="radius_km"
                                        x-cloak
                                        x-bind:hidden="! geolocationPermitted"
                                        @if(! $this->showsGeolocationControls()) hidden @endif
                                        class="inline-flex h-11 items-center gap-2 rounded-xl border border-emerald-200 bg-white px-3">
                                        <span class="text-xs font-semibold text-slate-500">{{ __('Radius') }}</span>
                                        <input type="number" min="1" max="1000" step="1" inputmode="numeric"
                                            wire:model.live.debounce.500ms="filterData.radius_km"
                                            data-signal-control="radius_km"
                                            aria-label="{{ __('Radius') }}"
                                            class="w-16 border-0 bg-transparent p-0 text-center text-sm font-bold text-emerald-900 outline-none focus:ring-0" />
                                        <span class="text-xs font-semibold text-slate-500">km</span>
                                    </label>
                                    <button type="button" wire:click="clearLocation" class="inline-flex min-h-11 items-center rounded-xl px-3 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 hover:text-rose-700">
                                        {{ __('Clear') }}
                                    </button>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div x-show="locationNotice" x-cloak x-text="locationNotice" class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold leading-5 text-amber-800"></div>

                    <div class="mi-filter-shell" wire:key="event-filter-form-{{ $filterFormVersion }}">
                        <div class="mb-5 max-w-xs">
                            <p class="mb-2 text-xs font-bold text-slate-700">{{ __('Susun') }}</p>
                            {{ $this->sortForm }}
                        </div>
                        {{ $this->form }}
                    </div>

                    <div class="mt-5 flex flex-col gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
                        <button type="button" wire:click="clearAllFilters"
                            data-signal-event="filter.cleared"
                            data-signal-category="filter"
                            data-signal-component="events_index_filters"
                            data-signal-control="clear_all"
                            aria-label="{{ __('Clear All Filters') }}"
                            class="inline-flex min-h-11 items-center gap-2 text-xs font-semibold text-amber-700 transition hover:text-amber-800">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 9 3-3m0 0 3 3m-3-3v9.75a4.5 4.5 0 0 0 4.5 4.5h4.5a4.5 4.5 0 0 0 4.5-4.5V6.75" />
                            </svg>
                            {{ __('Set semula semua') }}
                        </button>
                        <span class="text-xs font-medium text-slate-400">{{ __('Keputusan dikemas kini secara automatik.') }}</span>
                    </div>
                </div>
            </div>
        </section>
