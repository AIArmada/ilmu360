@section('title', $reference->displayTitle() . ' - ' . config('app.name'))
@section('meta_description', \Illuminate\Support\Str::limit(trim(strip_tags((string) $reference->descriptionValue())) ?: __('Lihat rujukan, butiran penerbitan, dan pautan perkongsian untuk :title di :app.', ['title' => $reference->displayTitle(), 'app' => config('app.name')]), 160))
@section('meta_robots', ((string) $reference->status === 'verified') ? 'index, follow' : 'noindex, nofollow')
@section('og_url', route('references.show', $reference))
@section('og_image', $reference->getFirstMediaUrl('front_cover', 'thumb') ?: ($reference->getFirstMediaUrl('front_cover') ?: asset('images/default-mosque-hero.png')))
@section('og_image_alt', __('Rujukan :title', ['title' => $reference->displayTitle()]))

@php
    $referenceTitle = $reference->displayTitle();
    $referenceDescription = trim(strip_tags((string) $reference->descriptionValue()));
    $referenceUrl = route('references.show', $reference);
    $frontCoverUrl = $reference->getFirstMediaUrl('front_cover', 'thumb') ?: ($reference->getFirstMediaUrl('front_cover') ?: asset('images/default-mosque-hero.png'));
    $backCoverUrl = $reference->getFirstMediaUrl('back_cover', 'thumb') ?: $reference->getFirstMediaUrl('back_cover');
    $referenceUrlExternal = trim((string) $reference->url);
    $socialLinks = $reference->socialProfiles
        ->filter(function ($social): bool {
            $resolvedUrl = $social->resolved_url ?? $social->url;

            return filled($social->platform) && filled($resolvedUrl);
        })
        ->values();
    $shareText = trim($referenceTitle . ' - ' . config('app.name'));
    $shareLinks = app(\App\Services\ShareTrackingService::class)->redirectLinks(
        $referenceUrl,
        $shareText,
        $referenceTitle,
    );
    $shareData = [
        'title' => $referenceTitle,
        'text' => $referenceDescription !== '' ? \Illuminate\Support\Str::limit($referenceDescription, 180) : __('Lihat rujukan ini di :app', ['app' => config('app.name')]),
        'url' => $referenceUrl,
        'sourceUrl' => $referenceUrl,
        'shareText' => $shareText,
        'fallbackTitle' => $referenceTitle,
        'payloadEndpoint' => route('share.payload'),
    ];
    $referenceRouteSegment = \App\Shared\Contributions\Enums\ContributionSubjectType::Reference->publicRouteSegment();
    $authorNames = $reference->effectiveAuthorNamesList();
    $metadataItems = array_filter([
        $reference->publisherValue(),
        filled($reference->isbn) ? 'ISBN '.$reference->isbn : null,
        filled($reference->language) ? __('Bahasa').': '.(app(\App\Shared\Cache\SelectionCatalogCache::class)->languageLabel($reference->language) ?? $reference->language) : null,
        filled($reference->year) ? (string) $reference->year : null,
    ]);
    $upcomingEvents = $this->upcomingEvents;
    $savedEventIds = $this->savedEventIds;
    $goingEventIds = $this->goingEventIds;
    $upcomingTotal = $this->upcomingTotal;
    $upcomingOverallTotal = $this->upcomingOverallTotal;
    $pastOverallTotal = $this->pastOverallTotal;
    $showCancelledEventStatusNotice = $upcomingEvents->contains(
        fn (\App\Data\PublicScheduleLeaf $leaf): bool => (string) $leaf->event->status === 'cancelled'
    );

@endphp

<div class="min-h-screen bg-[#f7f6f1] text-slate-900">
    <section class="relative isolate overflow-hidden border-b border-emerald-950/10 bg-[#f4efe4]">
        <div class="absolute inset-0 -z-20 bg-[radial-gradient(circle_at_12%_15%,rgba(201,154,55,0.18),transparent_28%),radial-gradient(circle_at_88%_8%,rgba(5,98,76,0.18),transparent_34%),linear-gradient(135deg,#fffdf8_0%,#f3eee2_55%,#e6eee8_100%)]"></div>
        <div class="absolute inset-0 -z-10 opacity-[0.24]" style="background-image: radial-gradient(circle at 1px 1px, rgba(6,78,59,.22) 1px, transparent 0); background-size: 26px 26px;"></div>
        <div class="absolute -right-24 -top-28 -z-10 h-96 w-96 rounded-full border border-emerald-900/10"></div>
        <div class="absolute -right-8 -top-12 -z-10 h-72 w-72 rounded-full border border-amber-700/10"></div>

        <div class="mx-auto max-w-7xl px-4 pb-6 pt-32 sm:px-6 sm:pb-8 sm:pt-36 lg:px-8 lg:pb-10 lg:pt-40">
            <div class="overflow-hidden rounded-[2rem] bg-white/82 shadow-[0_30px_90px_-42px_rgba(6,78,59,0.42)] backdrop-blur-xl">
                <div class="grid lg:grid-cols-[20rem_minmax(0,1fr)]">
                    <div class="relative min-h-[20rem] overflow-hidden bg-gradient-to-br from-emerald-100 via-[#f4efe4] to-amber-100 lg:min-h-[24rem]">
                        <div class="absolute inset-0 opacity-40" style="background-image: radial-gradient(circle at 1px 1px, rgba(7,91,72,.2) 1px, transparent 0); background-size: 20px 20px;"></div>
                        <img
                            src="{{ $frontCoverUrl }}"
                            alt="{{ $referenceTitle }}"
                            class="relative mx-auto h-full w-full object-contain p-6 drop-shadow-xl"
                            loading="eager"
                        >
                    </div>

                    <div class="flex flex-col p-6 sm:p-8 lg:p-8">
                        <div class="flex flex-1 flex-col">
                            <div class="flex flex-1 flex-col">
                                <h1 class="max-w-3xl font-heading text-4xl font-bold leading-[1.05] tracking-[-0.035em] text-emerald-950 sm:text-5xl lg:text-6xl">
                                    {{ $referenceTitle }}
                                    @if((string) $reference->status === 'verified')
                                        <x-ui.verified-badge class="text-emerald-600" data-testid="reference-verified-badge" />
                                    @endif
                                </h1>

                                @if($authorNames !== [] || $metadataItems !== [])
                                    <div class="mt-4 flex items-start gap-2 text-sm leading-6 text-slate-600 sm:text-base">
                                        <flux:icon.user-group class="mt-1 size-5 shrink-0 text-amber-600" />
                                        <div class="min-w-0">
                                            @foreach($authorNames as $authorName)
                                                <p>{{ $authorName }}</p>
                                            @endforeach
                                            @if($metadataItems !== [])
                                                <p class="{{ $authorNames !== [] ? 'mt-1' : '' }} text-slate-500">{{ implode(' · ', $metadataItems) }}</p>
                                            @endif
                                        </div>
                                    </div>
                                @endif

                                <div class="mt-auto grid grid-cols-2 gap-3 pt-4 sm:max-w-md">
                                    <div class="rounded-2xl border border-emerald-100 bg-emerald-50/70 p-3 text-center">
                                        <p class="font-heading text-2xl font-bold text-emerald-950">{{ number_format($upcomingOverallTotal) }}</p>
                                        <p class="mt-1 text-[10px] font-semibold text-emerald-700">{{ __('Majlis akan datang') }}</p>
                                    </div>
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-3 text-center">
                                        <p class="font-heading text-2xl font-bold text-emerald-950">{{ number_format($pastOverallTotal) }}</p>
                                        <p class="mt-1 text-[10px] font-semibold text-slate-500">{{ __('Majlis lepas') }}</p>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-6 flex flex-row flex-wrap gap-3">
                                <button
                                    type="button"
                                    wire:click="toggleFollow"
                                    wire:loading.attr="disabled"
                                    class="inline-flex h-12 min-w-0 flex-1 items-center justify-center gap-2 rounded-2xl bg-emerald-800 px-6 text-sm font-bold text-white shadow-lg shadow-emerald-900/15 transition hover:-translate-y-0.5 hover:bg-emerald-700 disabled:cursor-wait disabled:opacity-70 sm:flex-none"
                                >
                                    <svg class="h-5 w-5" fill="{{ $this->isFollowing ? 'currentColor' : 'none' }}" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185v15.065L12 16.197l-7.5 4.375V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0 1 11.186 0Z" />
                                    </svg>
                                    <span wire:loading.remove wire:target="toggleFollow">
                                        @if($this->isFollowing)
                                            <span class="sm:hidden">{{ __('Mengikuti') }}</span>
                                            <span class="hidden sm:inline">{{ __('Mengikuti Rujukan') }}</span>
                                        @else
                                            <span class="sm:hidden">{{ __('Ikuti') }}</span>
                                            <span class="hidden sm:inline">{{ __('Ikuti Rujukan') }}</span>
                                        @endif
                                    </span>
                                    <span wire:loading wire:target="toggleFollow">{{ __('Memproses...') }}</span>
                                </button>

                                <a
                                    href="#reference-share-panel"
                                    class="inline-flex h-12 min-w-0 flex-1 items-center justify-center gap-2 rounded-2xl border border-emerald-200 bg-white px-6 text-sm font-bold text-emerald-800 transition hover:-translate-y-0.5 hover:border-emerald-300 hover:bg-emerald-50 sm:flex-none"
                                >
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 12a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm12-6a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm0 12a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-11.11-7.1 7.22-3.8m-7.22 9.8 7.22 3.8" />
                                    </svg>
                                    <span class="sm:hidden">{{ __('Kongsi') }}</span>
                                    <span class="hidden sm:inline">{{ __('Kongsi Rujukan') }}</span>
                                </a>

                                @if(auth()->user()?->hasAnyRole(['super_admin', 'admin']))
                                    <a
                                        href="{{ \App\Filament\Resources\References\ReferenceResource::getUrl('edit', ['record' => $reference], panel: 'admin') }}"
                                        target="_blank"
                                        class="inline-flex h-12 items-center justify-center gap-2 rounded-2xl border border-amber-200 bg-amber-50 px-6 text-sm font-bold text-amber-800 transition hover:-translate-y-0.5 hover:border-amber-300 hover:bg-amber-100"
                                    >
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                        </svg>
                                        {{ __('Edit') }}
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="min-w-0 space-y-8">
                @if($referenceDescription !== '')
                    <section class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        <p class="text-[10px] font-black uppercase tracking-[0.22em] text-amber-700">{{ __('Tentang Rujukan') }}</p>
                        <h2 class="mt-1 font-heading text-xl font-bold text-emerald-950">{{ __('Ringkasan') }}</h2>
                        <div class="mt-3 text-sm leading-7 text-slate-600">{!! nl2br(e($referenceDescription)) !!}</div>
                    </section>
                @endif

                <section class="scroll-reveal reveal-up revealed space-y-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 class="font-heading text-3xl font-bold text-emerald-950">{{ __('Majlis Akan Datang') }}</h2>
                        </div>

                        @if($upcomingEvents->isNotEmpty())
                            <span class="inline-flex w-fit shrink-0 items-center gap-2 rounded-full border-emerald-300 bg-emerald-100 text-emerald-900 shadow-emerald-200/80 hover:bg-emerald-200 px-3 py-1.5 text-xs font-bold shadow-sm">
                                <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-600"></span></span>
                                {{ trans_choice(
                                    $upcomingDateFilter === 'all' ? ':count majlis aktif' : ':count majlis dipaparkan',
                                    $upcomingTotal,
                                    ['count' => number_format($upcomingTotal)]
                                ) }}
                            </span>
                        @endif
                    </div>

                    <div class="flex w-full min-w-0 items-center justify-center gap-2 sm:gap-3">
                        <div class="min-w-0 flex-1 overflow-x-auto overscroll-x-contain pb-1 [scrollbar-color:#86bfae_transparent] [scrollbar-width:thin] [&::-webkit-scrollbar]:h-1.5 [&::-webkit-scrollbar-track]:rounded-full [&::-webkit-scrollbar-track]:bg-emerald-50 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-emerald-300">
                            <flux:radio.group
                                variant="segmented"
                                size="sm"
                                wire:model.live="upcomingDateFilter"
                                wire:loading.attr="disabled"
                                wire:target="upcomingDateFilter,applyCustomDateRange,clearUpcomingDateFilter"
                                aria-label="{{ __('Tapis majlis akan datang') }}"
                                data-signal-event="navigation.upcoming_date_filter_changed"
                                data-signal-component="reference_detail_upcoming_events"
                                data-signal-control="date_filter"
                                class="w-max min-w-max"
                            >
                                @foreach([
                                    'all' => __('Semua'),
                                    'today' => __('Hari ini'),
                                    'tomorrow' => __('Esok'),
                                    'this_week' => __('Minggu ini'),
                                    'this_weekend' => __('Hujung minggu'),
                                    'next_week' => __('Minggu depan'),
                                    'this_month' => __('Bulan ini'),
                                    'next_month' => __('Bulan depan'),
                                ] as $filter => $label)
                                    <flux:radio
                                        value="{{ $filter }}"
                                        class="!text-emerald-950 hover:!text-emerald-800 dark:!text-emerald-950 dark:hover:!text-emerald-800 data-checked:!bg-emerald-700 data-checked:!text-white dark:data-checked:!bg-emerald-700 dark:data-checked:!text-white"
                                    >
                                        {{ $label }}
                                    </flux:radio>
                                @endforeach
                            </flux:radio.group>
                        </div>

                        <div class="shrink-0">
                            <flux:modal.trigger name="custom-date-range">
                                <flux:button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    square
                                    icon="calendar-days"
                                    aria-label="{{ __('Tentukan tarikh') }}"
                                    aria-pressed="{{ $upcomingDateFilter === 'custom' ? 'true' : 'false' }}"
                                    class="shrink-0 rounded-full! {{ $upcomingDateFilter === 'custom' ? 'bg-emerald-100! text-emerald-800! ring-1 ring-emerald-200!' : 'text-emerald-700! hover:bg-emerald-50!' }}"
                                />
                            </flux:modal.trigger>
                        </div>

                        <span
                            class="hidden size-7 shrink-0 items-center justify-center"
                            wire:loading.class.remove="hidden"
                            wire:target="upcomingDateFilter,applyCustomDateRange,clearUpcomingDateFilter"
                            role="status"
                            aria-live="polite"
                            aria-atomic="true"
                        >
                            <span
                                wire:loading.class.remove="hidden"
                                wire:target="upcomingDateFilter,applyCustomDateRange,clearUpcomingDateFilter"
                                class="hidden size-4 animate-spin rounded-full border-2 border-emerald-200 border-t-emerald-700"
                                style="animation-duration: 700ms"
                                aria-label="{{ __('Menapis...') }}"
                            ></span>
                        </span>
                    </div>

                    <flux:modal
                        wire:model="showCustomDateRange"
                        name="custom-date-range"
                        class="max-w-xl bg-white! text-emerald-950! ring-emerald-100! shadow-[0_24px_70px_-35px_rgba(6,78,59,0.35)]!"
                    >
                        <div class="space-y-6 text-emerald-950">
                            <div>
                                <flux:heading size="lg" class="text-emerald-950!">{{ __('Tentukan tarikh') }}</flux:heading>
                                <flux:subheading class="text-slate-500!">{{ __('Pilih tarikh mula dan tarikh akhir untuk menapis majlis akan datang.') }}</flux:subheading>
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <flux:field>
                                    <flux:label class="text-slate-700!">{{ __('Tarikh mula') }}</flux:label>
                                    <flux:input
                                        type="date"
                                        wire:model="customStartDate"
                                        class:input="bg-white! text-emerald-950! border-slate-200! border-b-slate-300! dark:bg-white! dark:text-emerald-950! dark:border-slate-200! dark:border-b-slate-300! placeholder:text-slate-400! dark:placeholder:text-slate-400!"
                                        class="bg-white! text-emerald-950! ring-slate-200! dark:bg-white! dark:text-emerald-950! dark:ring-slate-200!"
                                        style="color-scheme: light"
                                    />
                                </flux:field>

                                <flux:field>
                                    <flux:label class="text-slate-700!">{{ __('Tarikh akhir') }}</flux:label>
                                    <flux:input
                                        type="date"
                                        wire:model="customEndDate"
                                        min="{{ $customStartDate }}"
                                        class:input="bg-white! text-emerald-950! border-slate-200! border-b-slate-300! dark:bg-white! dark:text-emerald-950! dark:border-slate-200! dark:border-b-slate-300! placeholder:text-slate-400! dark:placeholder:text-slate-400!"
                                        class="bg-white! text-emerald-950! ring-slate-200! dark:bg-white! dark:text-emerald-950! dark:ring-slate-200!"
                                        style="color-scheme: light"
                                    />
                                </flux:field>
                            </div>

                            @error('customDateRange')
                                <p class="text-xs font-semibold text-red-600">{{ $message }}</p>
                            @enderror

                            <div class="flex justify-end gap-2">
                                <flux:modal.close>
                                    <flux:button type="button" variant="ghost" class="text-emerald-700! hover:bg-emerald-50! dark:text-emerald-700!">{{ __('Batal') }}</flux:button>
                                </flux:modal.close>
                                <flux:button
                                    type="button"
                                    variant="primary"
                                    color="emerald"
                                    wire:click="applyCustomDateRange"
                                    wire:loading.attr="disabled"
                                    wire:target="applyCustomDateRange"
                                    class="bg-emerald-600! text-white! hover:bg-emerald-700!"
                                >
                                    {{ __('Tapis tarikh') }}
                                </flux:button>
                            </div>
                        </div>
                    </flux:modal>

                    <x-public.moderation-status-note
                        :show-cancelled="$showCancelledEventStatusNotice"
                    />

                    <div
                        class="space-y-4"
                        wire:loading.class="opacity-60"
                        wire:loading.attr="aria-busy"
                        wire:target="upcomingDateFilter,applyCustomDateRange,clearUpcomingDateFilter"
                    >
                        @if($upcomingEvents->isNotEmpty())
                            <div class="flex flex-wrap justify-center gap-4 *:w-full md:*:w-[calc(50%_-_0.5rem)]">
                                @foreach($upcomingEvents as $scheduleLeaf)
                                    <x-events.schedule-card
                                        :schedule-leaf="$scheduleLeaf"
                                        :saved-event-ids="$savedEventIds"
                                        :is-going="in_array((string) $scheduleLeaf->event->getKey(), $goingEventIds, true)"
                                        :hide-reference="true"
                                        signal-component="reference_detail_upcoming_events"
                                        testid-prefix="reference-detail-card"
                                        :wire-key="'upcoming-'.$scheduleLeaf->entityType().'-'.$scheduleLeaf->id()"
                                    />
                                @endforeach
                            </div>
                        @else
                            <div class="rounded-2xl border border-dashed border-slate-200 bg-white p-10 text-center">
                                <h3 class="font-heading text-xl font-bold text-emerald-950">
                                    {{ $upcomingDateFilter === 'all' ? __('Belum ada majlis akan datang') : __('Tiada majlis untuk tempoh ini') }}
                                </h3>
                                <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">
                                    {{ $upcomingDateFilter === 'all'
                                        ? __('Ikuti rujukan ini untuk mengetahui apabila jadual majlis baharu diterbitkan.')
                                        : __('Cuba tempoh lain atau paparkan semua majlis akan datang.') }}
                                </p>

                                @if($upcomingDateFilter !== 'all')
                                    <button
                                        type="button"
                                        wire:click="clearUpcomingDateFilter"
                                        wire:loading.attr="disabled"
                                        wire:target="upcomingDateFilter,applyCustomDateRange,clearUpcomingDateFilter"
                                        class="mt-5 inline-flex items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-xs font-bold text-white transition hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-60"
                                    >
                                        {{ __('Tunjukkan semua majlis') }}
                                    </button>
                                @endif
                            </div>
                        @endif

                        @if($upcomingTotal > $upcomingEvents->count())
                            <div class="text-center">
                                <button
                                    type="button"
                                    wire:click="loadMoreUpcoming"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                >
                                    {{ __('Lihat Lagi') }}
                                </button>
                            </div>
                        @endif
                    </div>

                    <x-public.past-events-cta
                        :count="$this->pastTotal"
                        :url="route('events.index', ['reference_ids' => [(string) $reference->getKey()], 'time_scope' => 'past', 'filters_panel' => 'closed'])"
                        :description="trans_choice(':count majlis telah berlangsung menggunakan rujukan ini.', $this->pastTotal, ['count' => number_format($this->pastTotal)])"
                        test-id="reference-past-events-cta"
                        signal-component="reference_detail_past_events"
                    />
                </section>

                @if($reference->parentReference || $reference->childReferences->isNotEmpty())
                    <section class="scroll-reveal reveal-up revealed rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Keluarga Rujukan') }}</h2>

                        <div class="mt-4 space-y-4">
                            @if($reference->parentReference?->parentReference)
                                <a href="{{ route('references.show', $reference->parentReference->parentReference) }}" wire:navigate class="text-sm font-semibold text-emerald-700">{{ $reference->parentReference->parentReference->displayTitle() }}</a>
                            @endif
                            @if($reference->parentReference)
                                <div class="rounded-2xl border border-slate-200 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ __('Rujukan Induk') }}</p>
                                    <a
                                        href="{{ route('references.show', $reference->parentReference) }}"
                                        wire:navigate
                                        class="mt-2 inline-flex text-sm font-semibold text-emerald-700 transition hover:text-emerald-800"
                                    >
                                        {{ $reference->parentReference->displayTitle() }}
                                    </a>
                                </div>
                            @endif

                            @if($reference->childReferences->isNotEmpty())
                                <div class="rounded-2xl border border-slate-200 p-4">
                                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ $reference->isEdition() ? __('Bahagian Edisi Ini') : __('Edisi dan Bahagian') }}</p>
                                    <ul class="mt-3 space-y-3">
                                        @foreach($reference->childReferences as $childReference)
                                            <li>
                                                <a
                                                    href="{{ route('references.show', $childReference) }}"
                                                    wire:navigate
                                                    class="inline-flex text-sm font-semibold text-slate-900 transition hover:text-emerald-700"
                                                >
                                                    {{ $childReference->displayTitle() }}
                                                </a>
                                                @if($childReference->isEdition() && $childReference->childReferences->isNotEmpty())
                                                    <ul class="mt-3 space-y-2 border-l border-slate-200 pl-4">
                                                        @foreach($childReference->childReferences as $editionPart)
                                                            <li>
                                                                <a href="{{ route('references.show', $editionPart) }}" wire:navigate class="text-sm text-slate-600 hover:text-emerald-700">{{ $editionPart->displayTitle() }}</a>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    </section>
                @endif

                @if($referenceUrlExternal !== '' || $socialLinks->isNotEmpty())
                    <section class="scroll-reveal reveal-up revealed rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Pautan') }}</h2>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            @if($referenceUrlExternal !== '')
                                <a
                                    href="{{ $referenceUrlExternal }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="rounded-2xl border border-slate-200 px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                >
                                    {{ __('Sumber Rujukan') }}
                                </a>
                            @endif
                            @foreach($socialLinks as $social)
                                <a
                                    href="{{ $social->resolved_url ?? $social->url }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="rounded-2xl border border-slate-200 px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                >
                                    {{ \Illuminate\Support\Str::headline((string) $social->platform) }}
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>

            <aside class="space-y-6 lg:sticky lg:top-24 lg:self-start">
                @if($backCoverUrl !== '')
                    <section class="scroll-reveal reveal-right revealed rounded-[1.5rem] border border-slate-200 bg-white p-4 shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ __('Kulit Belakang') }}</p>
                        <img
                            src="{{ $backCoverUrl }}"
                            alt="{{ __('Kulit belakang :title', ['title' => $referenceTitle]) }}"
                            class="mt-4 w-full rounded-2xl object-cover"
                            loading="lazy"
                        >
                    </section>
                @endif

                <x-public-record-feedback
                    share-panel-id="reference-share-panel"
                    :subject-type="$referenceRouteSegment"
                    :subject-id="$reference->slug"
                    :share-data="$shareData"
                    :share-links="$shareLinks"
                />

                <x-sidebar-inspiration />
            </aside>
        </div>
    </div>
</div>
