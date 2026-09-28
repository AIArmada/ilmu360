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
    $referenceRedirectUrl = route('references.show', $reference, absolute: false);
    $frontCoverUrl = $reference->getFirstMediaUrl('front_cover', 'thumb') ?: ($reference->getFirstMediaUrl('front_cover') ?: asset('images/default-mosque-hero.png'));
    $backCoverUrl = $reference->getFirstMediaUrl('back_cover', 'thumb') ?: $reference->getFirstMediaUrl('back_cover');
    $referenceUrlExternal = trim((string) $reference->url);
    $socialLinks = $reference->socialProfiles
        ->filter(function ($social): bool {
            $resolvedUrl = $social->resolved_url ?? $social->url;

            return filled($social->platform) && filled($resolvedUrl);
        })
        ->values();
    $referenceTypeLabel = \App\Enums\ReferenceType::tryFrom((string) $reference->typeValue())?->getLabel()
        ?? \Illuminate\Support\Str::headline((string) $reference->typeValue());
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
        'payloadEndpoint' => route('dawah-share.payload'),
    ];
    $referenceRouteSegment = \App\Enums\ContributionSubjectType::Reference->publicRouteSegment();
    $metadataItems = array_filter([
        $referenceTypeLabel !== '' ? $referenceTypeLabel : null,
        $reference->authorValue(),
        $reference->publisherValue(),
        filled($reference->year) ? (string) $reference->year : null,
    ]);
    $upcomingEvents = $this->upcomingEvents;
    $pastEvents = $this->pastEvents;
    $upcomingTotal = $this->upcomingTotal;
    $pastTotal = $this->pastTotal;
    $showPendingEventStatusNotice = $upcomingEvents->concat($pastEvents)->contains(
        fn (\App\Models\Event $event): bool => (string) $event->status === 'pending'
    );
    $showCancelledEventStatusNotice = $upcomingEvents->concat($pastEvents)->contains(
        fn (\App\Models\Event $event): bool => (string) $event->status === 'cancelled'
    );

    $resolveEventCategoryLabel = static fn (\App\Models\Event $event): string => app(\App\Support\Events\EventCategoryPresenter::class)->forEvent($event)[0]['path'] ?? __('Umum');

    $resolveVenueLocation = static function (\App\Models\Event $event): string {
        $venueName = $event->venue?->name;
        $address = $event->venue?->primaryAddress();
        $parts = \App\Support\Location\AddressHierarchyFormatter::parts($address);
        $addressValue = implode(', ', array_filter($parts));

        if (filled($venueName) && filled($addressValue)) {
            return $venueName . ' • ' . $addressValue;
        }

        if (filled($venueName)) {
            return (string) $venueName;
        }

        return $addressValue;
    };

    $joinEventPeopleNames = static function (\Illuminate\Support\Collection $names): string {
        return $names->join(', ', ' dan ');
    };

    $resolveEventPersonAvatarStack = static function (\App\Models\Event $event): array {
        $avatars = $event->persons
            ->map(function (\App\Models\Person $person): array {
                return [
                    'name' => trim((string) ($person->formatted_name !== '' ? $person->formatted_name : $person->name)),
                    'url' => $person->public_avatar_url,
                ];
            })
            ->filter(fn (array $avatar): bool => $avatar['name'] !== '' && $avatar['url'] !== '')
            ->unique('name')
            ->values();

        return [
            'items' => $avatars->take(3)->values(),
            'overflow' => max(0, $avatars->count() - 3),
        ];
    };

    $resolveEventPeople = static function (\App\Models\Event $event) use ($joinEventPeopleNames): array {
        $personSummary = $event->persons
            ->map(fn (\App\Models\Person $person): string => trim((string) ($person->formatted_name !== '' ? $person->formatted_name : $person->name)))
            ->filter(fn (string $name): bool => $name !== '')
            ->unique()
            ->values();

        $roleSummary = $event->keyPeople
            ->filter(function (\App\Models\EventKeyPerson $keyPerson): bool {
                $role = $keyPerson->role_code;
                $role = $role instanceof \App\Enums\EventKeyPersonRole
                    ? $role
                    : \App\Enums\EventKeyPersonRole::tryFrom((string) $role);

                return $keyPerson->visibility === 'public' && $role !== \App\Enums\EventKeyPersonRole::Speaker;
            })
            ->groupBy(function (\App\Models\EventKeyPerson $keyPerson): string {
                $role = $keyPerson->role_code;

                return $role instanceof \App\Enums\EventKeyPersonRole
                    ? $role->value
                    : (string) $role;
            })
            ->map(function (\Illuminate\Support\Collection $keyPeople, string $role) use ($joinEventPeopleNames): ?string {
                $names = $keyPeople
                    ->map(fn (\App\Models\EventKeyPerson $keyPerson): string => trim((string) ($keyPerson->display_name
                        ?: $keyPerson->person?->formatted_name
                        ?: $keyPerson->person?->name
                        ?: '')))
                    ->filter(fn (string $name): bool => $name !== '')
                    ->unique()
                    ->values();

                if ($names->isEmpty()) {
                    return null;
                }

                $roleLabel = \App\Enums\EventKeyPersonRole::tryFrom($role)?->getLabel()
                    ?? \Illuminate\Support\Str::headline($role);

                return $roleLabel . ': ' . $joinEventPeopleNames($names);
            })
            ->filter()
            ->implode(' • ');

        return [
            'persons' => $personSummary->isNotEmpty() ? $joinEventPeopleNames($personSummary) : '',
            'roles' => $roleSummary,
        ];
    };
@endphp

<div class="min-h-screen bg-slate-50/90">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <x-ui.breadcrumbs
            class="mb-6"
            :items="[
                ['label' => __('Laman Utama'), 'url' => route('home'), 'icon' => 'home'],
                ['label' => __('Rujukan'), 'url' => route('references.index'), 'icon' => 'book', 'show_label' => true],
            ]"
        />
        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="space-y-8">
                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="grid gap-0 md:grid-cols-[15rem_minmax(0,1fr)]">
                        <div class="bg-slate-100">
                            <img
                                src="{{ $frontCoverUrl }}"
                                alt="{{ $referenceTitle }}"
                                class="h-full min-h-72 w-full object-cover"
                                loading="lazy"
                            >
                        </div>

                        <div class="space-y-6 p-6 sm:p-8">
                            <div class="flex flex-col gap-6 sm:flex-row sm:items-start sm:justify-between">
                                <div class="space-y-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if($referenceTypeLabel !== '')
                                            <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-900">
                                                {{ $referenceTypeLabel }}
                                            </span>
                                        @endif

                                        @if((string) $reference->status !== 'verified')
                                            <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">
                                                {{ __('Menunggu Kelulusan') }}
                                            </span>
                                        @endif
                                    </div>

                                    <div>
                                        <h1 class="font-heading text-3xl font-bold text-slate-950 sm:text-4xl">{{ $referenceTitle }}</h1>

                                        @if($metadataItems !== [])
                                            <p class="mt-3 text-sm text-slate-500">{{ implode(' · ', $metadataItems) }}</p>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center gap-3">
                                    <button
                                        type="button"
                                        wire:click="toggleFollow"
                                        wire:loading.attr="disabled"
                                        class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                    >
                                        @if($this->isFollowing)
                                            {{ __('Mengikuti') }}
                                        @else
                                            {{ __('Ikuti') }}
                                        @endif
                                    </button>

                                    <a
                                        href="#reference-share-panel"
                                        class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                    >
                                        {{ __('Kongsi') }}
                                    </a>
                                </div>
                            </div>

                            @if($referenceDescription !== '')
                                <div class="prose max-w-none text-slate-700 prose-headings:text-slate-950">
                                    <p>{{ $referenceDescription }}</p>
                                </div>
                            @endif

                            @guest
                                <div class="flex flex-wrap gap-3">
                                    <a
                                        href="{{ \App\Support\Auth\IntendedRedirect::registerUrl($referenceRedirectUrl) }}"
                                        class="inline-flex items-center gap-2 rounded-full bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700"
                                    >
                                        {{ __('Daftar') }}
                                    </a>
                                    <a
                                        href="{{ \App\Support\Auth\IntendedRedirect::loginUrl($referenceRedirectUrl) }}"
                                        class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                    >
                                        {{ __('Log Masuk') }}
                                    </a>
                                </div>
                            @endguest
                        </div>
                    </div>
                </section>

                <section class="scroll-reveal reveal-up revealed space-y-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.22em] text-amber-700">{{ __('Jadual Rujukan') }}</p>
                            <h2 class="mt-1 font-heading text-3xl font-bold text-emerald-950">{{ __('Majlis Akan Datang') }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('Majlis yang menggunakan rujukan ini.') }}</p>
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
                        :show-pending="$showPendingEventStatusNotice"
                        :show-cancelled="$showCancelledEventStatusNotice"
                    />

                    <div
                        class="space-y-4"
                        wire:loading.class="opacity-60"
                        wire:loading.attr="aria-busy"
                        wire:target="upcomingDateFilter,applyCustomDateRange,clearUpcomingDateFilter"
                    >
                        @foreach($upcomingEvents as $event)
                            @php
                                $venueLocation = $resolveVenueLocation($event);
                                $eventPeople = $resolveEventPeople($event);
                                $personAvatarStack = $resolveEventPersonAvatarStack($event);
                                $eventTypeLabel = $resolveEventCategoryLabel($event);
                                $bookReferenceTitle = $event->reference_study_subtitle;
                                $eventFormatValue = $event->delivery_mode?->value ?? $event->delivery_mode;
                                $isRemoteEvent = in_array($eventFormatValue, ['online', 'hybrid'], true);
                                $isPendingEvent = (string) $event->status === 'pending';
                                $isCancelledEvent = (string) $event->status === 'cancelled';
                            @endphp

                            <a
                                href="{{ route('events.show', $event) }}"
                                wire:key="upcoming-{{ $event->id }}"
                                wire:navigate
                                class="group relative flex overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-md"
                            >
                                <div class="flex w-[4.5rem] shrink-0 flex-col items-center justify-center bg-gradient-to-b {{ $isCancelledEvent ? 'from-rose-600 to-rose-800' : ($isPendingEvent ? 'from-amber-600 to-amber-800' : ($isRemoteEvent ? 'from-sky-600 to-sky-800' : 'from-emerald-600 to-emerald-800')) }} p-3 text-white sm:w-24">
                                    <span class="text-[10px] font-bold uppercase tracking-widest text-white/80">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'l') }}</span>
                                    <span class="font-heading text-3xl font-black leading-none sm:text-4xl">{{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'd') }}</span>
                                    <span class="mt-1 text-[11px] font-bold tracking-wide text-white/80">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'F') }}</span>
                                </div>

                                <div class="flex flex-1 flex-col gap-3 p-4 sm:p-5">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-900">
                                                {{ $eventTypeLabel }}
                                            </span>

                                            @if($isPendingEvent)
                                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-[11px] font-semibold text-amber-800">
                                                    {{ __('Menunggu Kelulusan') }}
                                                </span>
                                            @endif

                                            @if($isCancelledEvent)
                                                <span class="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-0.5 text-[11px] font-semibold text-rose-800">
                                                    {{ __('Dibatalkan') }}
                                                </span>
                                            @endif
                                        </div>

                                        @if($personAvatarStack['items']->isNotEmpty())
                                            <div class="flex -space-x-3" aria-label="{{ __('Penceramah') }}">
                                                @foreach($personAvatarStack['items'] as $avatar)
                                                    <img
                                                        src="{{ $avatar['url'] }}"
                                                        alt="{{ $avatar['name'] }}"
                                                        title="{{ $avatar['name'] }}"
                                                        class="h-9 w-9 rounded-full object-cover ring-2 ring-white shadow-sm sm:h-11 sm:w-11"
                                                    >
                                                @endforeach

                                                @if($personAvatarStack['overflow'] > 0)
                                                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-[10px] font-semibold text-slate-600 ring-2 ring-white shadow-sm sm:h-11 sm:w-11 sm:text-[11px]">
                                                        +{{ $personAvatarStack['overflow'] }}
                                                    </span>
                                                @endif
                                            </div>
                                        @endif
                                    </div>

                                    <div class="space-y-2">
                                        <h3 class="font-heading text-lg font-bold text-slate-950 transition group-hover:text-emerald-700">
                                            {{ $event->title }}
                                        </h3>

                                        @if($bookReferenceTitle)
                                            <p class="pl-3 text-sm font-bold italic text-slate-500 sm:pl-4">
                                                {{ $bookReferenceTitle }}
                                            </p>
                                        @endif
                                    </div>

                                    <div class="space-y-1.5 text-sm text-slate-500">
                                        <div class="flex items-center gap-2">
                                            <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <span>{{ $event->timing_display !== '' ? $event->timing_display : \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'h:i A') }}</span>

                                            @if($event->ends_at)
                                                <span class="text-slate-300">-</span>
                                                <span>{{ \App\Support\Timezone\UserDateTimeFormatter::format($event->ends_at, 'h:i A') }}</span>
                                            @endif
                                        </div>

                                        @if($eventPeople['persons'] !== '')
                                            <div class="flex items-start gap-2">
                                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.742-.479 3 3 0 00-4.682-2.72m.94 3.198v.75c0 .414-.336.75-.75.75H4.75a.75.75 0 01-.75-.75v-.75a4.5 4.5 0 014.5-4.5h4.5a4.5 4.5 0 014.5 4.5z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 7.5a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                                                </svg>
                                                <span class="line-clamp-2">{{ $eventPeople['persons'] }}</span>
                                            </div>
                                        @endif

                                        @if($eventPeople['roles'] !== '')
                                            <div class="flex items-start gap-2">
                                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.008v.008H3.75V6.75zm0 5.25h.008v.008H3.75V12zm0 5.25h.008v.008H3.75v-.008z" />
                                                </svg>
                                                <span class="line-clamp-2">{{ $eventPeople['roles'] }}</span>
                                            </div>
                                        @endif

                                        @if($venueLocation !== '' && ! $isRemoteEvent)
                                            <div class="flex items-center gap-2">
                                                <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                                                </svg>
                                                <span class="line-clamp-1">{{ $venueLocation }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </a>
                        @endforeach

                        @if($upcomingEvents->isEmpty())
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

                    @if($pastEvents->isNotEmpty())
                        <div class="space-y-4 pt-4">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-semibold text-slate-900">{{ __('Lepas') }}</h3>

                                @if($pastTotal > $pastEvents->count())
                                    <button
                                        type="button"
                                        wire:click="loadMorePast"
                                        wire:loading.attr="disabled"
                                        class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                    >
                                        {{ __('Lihat Lagi') }}
                                    </button>
                                @endif
                            </div>

                            @foreach($pastEvents as $event)
                                @php
                                    $venueLocation = $resolveVenueLocation($event);
                                    $eventPeople = $resolveEventPeople($event);
                                    $bookReferenceTitle = $event->reference_study_subtitle;
                                    $eventFormatValue = $event->delivery_mode?->value ?? $event->delivery_mode;
                                    $isRemoteEvent = in_array($eventFormatValue, ['online', 'hybrid'], true);
                                @endphp

                                <a
                                    href="{{ route('events.show', $event) }}"
                                    wire:key="past-{{ $event->id }}"
                                    wire:navigate
                                    class="group relative flex overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-md"
                                >
                                    <div class="flex w-[4.5rem] shrink-0 flex-col items-center justify-center bg-gradient-to-b from-slate-700 to-slate-900 p-3 text-white sm:w-24">
                                        <span class="text-[10px] font-bold uppercase tracking-widest text-white/80">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'l') }}</span>
                                        <span class="font-heading text-3xl font-black leading-none sm:text-4xl">{{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'd') }}</span>
                                        <span class="mt-1 text-[11px] font-bold tracking-wide text-white/80">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'F') }}</span>
                                    </div>

                                    <div class="flex flex-1 flex-col gap-3 p-4 sm:p-5">
                                        <h3 class="font-heading text-lg font-bold text-slate-950 transition group-hover:text-emerald-700">
                                            {{ $event->title }}
                                        </h3>

                                        @if($bookReferenceTitle)
                                            <p class="pl-3 text-sm font-bold italic text-slate-500 sm:pl-4">
                                                {{ $bookReferenceTitle }}
                                            </p>
                                        @endif

                                        <div class="space-y-1.5 text-sm text-slate-500">
                                            <div class="flex items-center gap-2">
                                                <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                                <span>{{ $event->timing_display !== '' ? $event->timing_display : \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'h:i A') }}</span>

                                                @if($event->ends_at)
                                                    <span class="text-slate-300">-</span>
                                                    <span>{{ \App\Support\Timezone\UserDateTimeFormatter::format($event->ends_at, 'h:i A') }}</span>
                                                @endif
                                            </div>

                                            @if($eventPeople['persons'] !== '')
                                                <div class="flex items-start gap-2">
                                                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.742-.479 3 3 0 00-4.682-2.72m.94 3.198v.75c0 .414-.336.75-.75.75H4.75a.75.75 0 01-.75-.75v-.75a4.5 4.5 0 014.5-4.5h4.5a4.5 4.5 0 014.5 4.5z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 7.5a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                                                    </svg>
                                                    <span class="line-clamp-2">{{ $eventPeople['persons'] }}</span>
                                                </div>
                                            @endif

                                            @if($venueLocation !== '' && ! $isRemoteEvent)
                                                <div class="flex items-center gap-2">
                                                    <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                                                    </svg>
                                                    <span class="line-clamp-1">{{ $venueLocation }}</span>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </section>

                @if($reference->parentReference || $reference->childReferences->isNotEmpty())
                    <section class="scroll-reveal reveal-up revealed rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Keluarga Rujukan') }}</h2>

                        <div class="mt-4 space-y-4">
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
                                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ __('Bahagian Berkaitan') }}</p>
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

            <aside class="space-y-6">
                @if($backCoverUrl !== '')
                    <section class="scroll-reveal reveal-right revealed rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ __('Kulit Belakang') }}</p>
                        <img
                            src="{{ $backCoverUrl }}"
                            alt="{{ __('Kulit belakang :title', ['title' => $referenceTitle]) }}"
                            class="mt-4 w-full rounded-2xl object-cover"
                            loading="lazy"
                        >
                    </section>
                @endif

                <section class="scroll-reveal reveal-right revealed rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-4">
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-slate-400">{{ __('Bantu Semak Rujukan') }}</p>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                {{ __('Jumpa maklumat yang perlu diperbetulkan atau kandungan yang meragukan?') }}
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <a
                                href="{{ route('contributions.suggest-update', ['subjectType' => $referenceRouteSegment, 'subjectId' => $reference->slug]) }}"
                                wire:navigate
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-sky-200 hover:bg-sky-50 hover:text-sky-700"
                            >
                                {{ __('Cadang Kemaskini') }}
                            </a>
                            <a
                                href="{{ route('reports.create', ['subjectType' => $referenceRouteSegment, 'subjectId' => $reference->slug]) }}"
                                wire:navigate
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                            >
                                {{ __('Lapor') }}
                            </a>
                        </div>
                    </div>
                </section>

                <section id="reference-share-panel" class="scroll-reveal reveal-right revealed">
                    <x-dawah-share-panel
                        :share-data="$shareData"
                        :share-links="$shareLinks"
                    />
                </section>

                <x-sidebar-inspiration />
            </aside>
        </div>
    </div>
</div>
