@php
    $speakerShareImageUrl = $speaker->public_avatar_url;
@endphp

@section('title', $speaker->formatted_name . ' - ' . config('app.name'))
@section('meta_description', \Illuminate\Support\Str::limit((is_array($speaker->bio) ? \Filament\Forms\Components\RichEditor\RichContentRenderer::make($speaker->bio)->toText() : trim(strip_tags((string) $speaker->bio))) ?: __('Lihat profil, biodata, dan jadual majlis oleh :name di :app.', ['name' => $speaker->formatted_name, 'app' => config('app.name')]), 160))
@section('meta_robots', ($speaker->is_active && $speaker->status === 'verified') ? 'index, follow' : 'noindex, nofollow')
@section('og_url', route('speakers.show', $speaker))
@section('og_image', $speakerShareImageUrl)
@section('og_image_alt', __('Profil penceramah :name', ['name' => $speaker->formatted_name]))

@php
    $bioRenderer = is_array($speaker->bio)
        ? \Filament\Forms\Components\RichEditor\RichContentRenderer::make($speaker->bio)
        : null;
    $bioHtml = is_array($speaker->bio) ? $bioRenderer?->toHtml() ?? '' : (string) $speaker->bio;
    $bioText = is_array($speaker->bio) ? trim($bioRenderer?->toText() ?? '') : trim(strip_tags((string) $speaker->bio));
    $shouldCollapseBio = \Illuminate\Support\Str::length($bioText) > 680;
    $locationString = \App\Support\Location\AddressHierarchyFormatter::format($speaker->addressModel);
    $upcomingEvents = $this->upcomingEvents;
    $pastEvents = $this->pastEvents;
    $upcomingTotal = $this->upcomingTotal;
    $pastTotal = $this->pastTotal;
    $otherRoleParticipations = $this->otherRoleParticipations;
    $speakerUrl = route('speakers.show', $speaker);
    $speakerRedirectUrl = route('speakers.show', $speaker, absolute: false);
    $shareText = trim($speaker->formatted_name . ' - ' . config('app.name'));
    $shareLinks = app(\App\Services\ShareTrackingService::class)->redirectLinks(
        $speakerUrl,
        $shareText,
        $speaker->formatted_name,
    );
    $shareData = [
        'title' => $speaker->formatted_name,
        'text' => $bioText !== '' ? \Illuminate\Support\Str::limit($bioText, 180) : __('Lihat profil penceramah ini di :app', ['app' => config('app.name')]),
        'url' => $speakerUrl,
        'sourceUrl' => $speakerUrl,
        'shareText' => $shareText,
        'fallbackTitle' => $speaker->formatted_name,
        'payloadEndpoint' => route('dawah-share.payload'),
    ];
    $socialLinks = $speaker->socialMedia
        ->filter(function ($social): bool {
            $resolvedUrl = $social->resolved_url ?? $social->url;

            return filled($social->platform) && filled($resolvedUrl);
        })
        ->values();
    $showPendingEventStatusNotice = $upcomingEvents->concat($pastEvents)->contains(
        fn (\App\Models\Event $event): bool => (string) $event->status === 'pending'
    );
    $showCancelledEventStatusNotice = $upcomingEvents->concat($pastEvents)->contains(
        fn (\App\Models\Event $event): bool => (string) $event->status === 'cancelled'
    );
    $speakerRouteSegment = \App\Enums\ContributionSubjectType::Speaker->publicRouteSegment();

    $resolveEventTypeLabel = static function (mixed $eventType): string {
        if ($eventType instanceof \Illuminate\Support\Collection) {
            $eventType = $eventType->first();
        } elseif (is_array($eventType)) {
            $eventType = $eventType[0] ?? null;
        }

        if ($eventType instanceof \App\Enums\EventType) {
            return $eventType->getLabel();
        }

        if (is_string($eventType) && $eventType !== '') {
            return \App\Enums\EventType::tryFrom($eventType)?->getLabel() ?? __('Umum');
        }

        return __('Umum');
    };

    $resolveEventLocation = static function (\App\Models\Event $event): string {
        $primaryLocationName = $event->venue?->name ?: $event->institution?->name;
        $address = $event->venue?->addressModel ?? $event->institution?->addressModel;
        $parts = \App\Support\Location\AddressHierarchyFormatter::parts($address);
        $stateName = \AIArmada\Addressing\Models\AddressArea::query()
            ->whereKey($address?->admin_area_1_id)
            ->where('level', 1)
            ->value('name');

        if (! is_string($stateName) || trim($stateName) === '') {
            $stateName = is_string($address?->state) ? trim($address->state) : null;
        }

        if (count($parts) === 1 && \App\Support\Location\FederalTerritoryLocation::isFederalTerritoryStateName($stateName)) {
            $parts[] = $stateName;
        }

        $locationParts = array_filter([
            $primaryLocationName,
            ...$parts,
        ]);

        return implode(', ', $locationParts);
    };

    $resolveKeyPersonRoleLabel = static function (\App\Models\EventKeyPerson $keyPerson): string {
        $role = $keyPerson->role;

        if ($role instanceof \App\Enums\EventKeyPersonRole) {
            return $role->getLabel();
        }

        if (is_string($role) && $role !== '') {
            return \App\Enums\EventKeyPersonRole::tryFrom($role)?->getLabel() ?? \Illuminate\Support\Str::headline($role);
        }

        return '';
    };
@endphp

<div class="min-h-screen bg-slate-50/90">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="space-y-8">
                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="space-y-6 p-6 sm:p-8">
                        <div class="flex flex-col gap-6 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex items-start gap-4">
                                <img
                                    src="{{ $speaker->public_avatar_url }}"
                                    alt="{{ $speaker->formatted_name }}"
                                    class="h-24 w-24 rounded-3xl object-cover"
                                    loading="lazy"
                                >

                                <div class="space-y-2">
                                    <h1 class="font-heading text-3xl font-bold text-slate-950 sm:text-4xl">{{ $speaker->formatted_name }}</h1>

                                    @if($locationString !== '')
                                        <p class="text-sm text-slate-500">{{ $locationString }}</p>
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
                                    href="#speaker-share-panel"
                                    class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                >
                                    {{ __('Kongsi') }}
                                </a>
                            </div>
                        </div>

                        @guest
                            <div class="flex flex-wrap gap-3">
                                <a
                                    href="{{ \App\Support\Auth\IntendedRedirect::registerUrl($speakerRedirectUrl) }}"
                                    class="inline-flex items-center gap-2 rounded-full bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700"
                                >
                                    {{ __('Daftar') }}
                                </a>
                                <a
                                    href="{{ \App\Support\Auth\IntendedRedirect::loginUrl($speakerRedirectUrl) }}"
                                    class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                >
                                    {{ __('Log Masuk') }}
                                </a>
                            </div>
                        @endguest
                    </div>
                </section>

                <section class="scroll-reveal reveal-up revealed rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Biodata') }}</h2>

                    @if($bioText !== '')
                        <div x-data="{ expanded: false }" class="mt-4">
                            <div class="{{ $shouldCollapseBio ? 'max-h-80 overflow-hidden' : '' }} prose max-w-none text-slate-700 prose-headings:text-slate-950" :class="{ 'max-h-80 overflow-hidden': {{ $shouldCollapseBio ? 'true' : 'false' }} && ! expanded }">
                                {!! $bioHtml !!}
                            </div>

                            @if($shouldCollapseBio)
                                <button
                                    type="button"
                                    x-on:click="expanded = ! expanded"
                                    class="mt-4 inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700"
                                >
                                    <span x-show="! expanded">{{ __('Lihat biodata penuh') }}</span>
                                    <span x-show="expanded">{{ __('Sembunyikan biodata') }}</span>
                                </button>
                            @endif
                        </div>
                    @else
                        <p class="mt-4 text-sm text-slate-500">{{ __('Tiada biodata tersedia buat masa ini.') }}</p>
                    @endif
                </section>

                @if($socialLinks->isNotEmpty())
                    <section class="scroll-reveal reveal-up revealed rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Media Sosial') }}</h2>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
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

                <section class="scroll-reveal reveal-up revealed space-y-6">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 class="font-heading text-2xl font-bold text-slate-950">{{ __('Majlis') }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ __('Majlis yang menampilkan penceramah ini.') }}</p>
                        </div>

                        @if($upcomingEvents->isNotEmpty())
                            <span class="inline-flex items-center rounded-xl border border-emerald-300 bg-emerald-100 text-emerald-900 shadow-emerald-200/80 hover:bg-emerald-200 px-3 py-2 text-sm font-semibold shadow-sm">
                                {{ __('Tarikh Aktif') }}
                            </span>
                        @endif
                    </div>

                    <x-public.moderation-status-note
                        :show-pending="$showPendingEventStatusNotice"
                        :show-cancelled="$showCancelledEventStatusNotice"
                    />

                    <div class="space-y-4">
                        @foreach($upcomingEvents as $event)
                            @php
                                $eventTypeLabel = $resolveEventTypeLabel($event->event_type);
                                $eventLocation = $resolveEventLocation($event);
                                $eventFormatValue = $event->event_format?->value ?? $event->event_format;
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

                                    <div class="space-y-2">
                                        <h3 class="font-heading text-lg font-bold text-slate-950 transition group-hover:text-emerald-700">
                                            {{ $event->title }}
                                        </h3>

                                        @if($event->reference_study_subtitle)
                                            <p class="pl-3 text-sm font-bold italic text-slate-500 sm:pl-4">
                                                {{ $event->reference_study_subtitle }}
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

                                        @if($eventLocation !== '' && ! $isRemoteEvent)
                                            <div class="flex items-center gap-2">
                                                <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                                                </svg>
                                                <span class="line-clamp-1">{{ $eventLocation }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </a>
                        @endforeach

                        @if($upcomingEvents->isEmpty())
                            <div class="rounded-2xl border border-dashed border-slate-200 bg-white p-10 text-center text-sm text-slate-500">
                                {{ __('Tiada majlis dijadualkan buat masa ini.') }}
                            </div>
                        @endif
                    </div>

                    @if($pastEvents->isNotEmpty())
                        <div class="space-y-4 pt-4">
                            <h3 class="text-lg font-semibold text-slate-900">{{ __('Lepas') }}</h3>

                            @foreach($pastEvents as $event)
                                @php
                                    $eventLocation = $resolveEventLocation($event);
                                    $eventFormatValue = $event->event_format?->value ?? $event->event_format;
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

                                        @if($event->reference_study_subtitle)
                                            <p class="pl-3 text-sm font-bold italic text-slate-500 sm:pl-4">
                                                {{ $event->reference_study_subtitle }}
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

                                            @if($eventLocation !== '' && ! $isRemoteEvent)
                                                <div class="flex items-center gap-2">
                                                    <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                                                    </svg>
                                                    <span class="line-clamp-1">{{ $eventLocation }}</span>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </section>

                @if($otherRoleParticipations->isNotEmpty())
                    <section class="scroll-reveal reveal-up revealed rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="font-heading text-2xl font-bold text-slate-950">{{ __('Peranan Lain Dalam Majlis') }}</h2>

                        <div class="mt-4 space-y-4">
                            @foreach($otherRoleParticipations as $keyPerson)
                                @php
                                    $event = $keyPerson->event;
                                    $roleLabel = $resolveKeyPersonRoleLabel($keyPerson);
                                @endphp

                                @if($event)
                                    <div class="rounded-2xl border border-slate-200 p-4">
                                        <p class="text-sm font-semibold text-emerald-700">{{ $roleLabel }}</p>
                                        <p class="mt-1 font-semibold text-slate-900">{{ $event->title }}</p>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>

            <aside class="space-y-6">
                <section class="scroll-reveal reveal-right revealed rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-4">
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-slate-400">{{ __('Bantu Semak Penceramah') }}</p>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                {{ __('Jumpa maklumat yang perlu diperbetulkan atau profil yang meragukan?') }}
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <a
                                href="{{ route('contributions.suggest-update', ['subjectType' => $speakerRouteSegment, 'subjectId' => $speaker->slug]) }}"
                                wire:navigate
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-sky-200 hover:bg-sky-50 hover:text-sky-700"
                            >
                                {{ __('Cadang Kemaskini') }}
                            </a>
                            <a
                                href="{{ route('reports.create', ['subjectType' => $speakerRouteSegment, 'subjectId' => $speaker->slug]) }}"
                                wire:navigate
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                            >
                                {{ __('Lapor') }}
                            </a>
                        </div>
                    </div>
                </section>

                <section id="speaker-share-panel" class="scroll-reveal reveal-right revealed">
                    <x-dawah-share-panel
                        :preview-title="$speaker->formatted_name"
                        :preview-subtitle="$locationString !== '' ? $locationString : null"
                        :share-data="$shareData"
                        :share-links="$shareLinks"
                    />
                </section>

                <x-sidebar-inspiration />
            </aside>
        </div>
    </div>
</div>
