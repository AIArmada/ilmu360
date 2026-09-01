@section('title', $institution->name . ' - ' . config('app.name'))
@section('meta_description', \Illuminate\Support\Str::limit(trim(strip_tags((string) $institution->description)) ?: __('Lihat profil, lokasi, saluran sumbangan, dan majlis akan datang oleh :name di :app.', ['name' => $institution->name, 'app' => config('app.name')]), 160))
@section('meta_robots', $institution->status === 'verified' ? 'index, follow' : 'noindex, nofollow')
@section('og_url', route('institutions.show', $institution))
@section('og_image', $institution->public_cover_url ?: $institution->public_image_url)
@section('og_image_alt', __('Profil institusi :name', ['name' => $institution->name]))

@php
    $address = $institution->primaryAddress();
    $institutionCoverUrl = $institution->public_cover_url ?: $institution->public_image_url;
    $typeLabel = $institution->type?->getLabel();
    $addressLines = \App\Support\Location\AddressHierarchyFormatter::displayLines($address);
    $locationString = \App\Support\Location\AddressHierarchyFormatter::format($address);
    $upcomingEvents = $this->upcomingEvents;
    $pastEvents = $this->pastEvents;
    $upcomingTotal = $this->upcomingTotal;
    $pastTotal = $this->pastTotal;
    $publicContacts = $institution->contactMethods->where('is_public', true)->values();
    $socialLinks = $institution->publicSocialProfiles
        ->filter(function ($social): bool {
            $resolvedUrl = $social->profileUrl() ?? $social->url;

            return filled($social->platform) && filled($resolvedUrl);
        })
        ->values();
    $donationChannels = $institution->donationChannels;
    $persons = $institution->persons;
    $spaces = $institution->spaces;
    $institutionUrl = route('institutions.show', $institution);
    $shareText = trim($institution->name . ' - ' . config('app.name'));
    $shareLinks = app(\App\Services\ShareTrackingService::class)->redirectLinks(
        $institutionUrl,
        $shareText,
        $institution->name,
    );
    $shareData = [
        'title' => $institution->name,
        'text' => __('Lihat profil institusi ini di :app', ['app' => config('app.name')]),
        'url' => $institutionUrl,
        'sourceUrl' => $institutionUrl,
        'shareText' => $shareText,
        'fallbackTitle' => $institution->name,
        'payloadEndpoint' => route('dawah-share.payload'),
    ];
    $institutionRouteSegment = \App\Enums\ContributionSubjectType::Institution->publicRouteSegment();
    $showPendingEventStatusNotice = $upcomingEvents->concat($pastEvents)->contains(
        fn (\App\Models\Event $event): bool => (string) $event->status === 'pending'
    );
    $showCancelledEventStatusNotice = $upcomingEvents->concat($pastEvents)->contains(
        fn (\App\Models\Event $event): bool => (string) $event->status === 'cancelled'
    );
    $mapQuery = implode(', ', array_filter([
        $institution->name,
        $address?->line1,
        $address?->line2,
        $addressLines['locality'],
        $addressLines['regional'],
    ]));
    $normalizedMapQuery = null;

    if (filled($address?->google_maps_url)) {
        $parsedQueryString = parse_url((string) $address->google_maps_url, PHP_URL_QUERY);

        if (is_string($parsedQueryString) && $parsedQueryString !== '') {
            parse_str($parsedQueryString, $queryParams);
            $queryValue = $queryParams['query'] ?? $queryParams['q'] ?? null;

            if (is_string($queryValue) && $queryValue !== '') {
                $normalizedMapQuery = $queryValue;
            }
        }
    }

    if (! filled($normalizedMapQuery) && filled($mapQuery)) {
        $normalizedMapQuery = $mapQuery;
    }

    if (! filled($normalizedMapQuery) && $address?->latitude !== null && $address?->longitude !== null) {
        $normalizedMapQuery = $address->latitude . ',' . $address->longitude;
    }

    $googleMapsEmbedUrl = filled($normalizedMapQuery)
        ? 'https://www.google.com/maps?q=' . urlencode((string) $normalizedMapQuery) . '&output=embed'
        : null;
    $googleMapsBrowseUrl = filled($address?->google_maps_url)
        ? (string) $address->google_maps_url
        : (filled($normalizedMapQuery) ? 'https://www.google.com/maps?q=' . urlencode((string) $normalizedMapQuery) : null);
    $wazeUrl = filled($address?->waze_url) ? (string) $address->waze_url : null;

    $formatContactHref = static function ($contact): ?string {
        return match ((string) $contact->type) {
            'phone', 'mobile' => 'tel:' . preg_replace('/\D+/', '', (string) $contact->value),
            'whatsapp' => 'https://wa.me/' . preg_replace('/\D+/', '', (string) $contact->value),
            'email' => 'mailto:' . (string) $contact->value,
            default => null,
        };
    };

    $contactPresentation = [
        'phone' => ['label' => __('Telefon'), 'icon' => 'phone', 'tone' => 'bg-emerald-50 text-emerald-700 ring-emerald-100'],
        'mobile' => ['label' => __('Telefon'), 'icon' => 'phone', 'tone' => 'bg-emerald-50 text-emerald-700 ring-emerald-100'],
        'whatsapp' => ['label' => 'WhatsApp', 'icon' => 'whatsapp', 'tone' => 'bg-green-50 text-green-700 ring-green-100'],
        'email' => ['label' => __('E-mel'), 'icon' => 'email', 'tone' => 'bg-amber-50 text-amber-700 ring-amber-100'],
    ];

    $socialPresentation = [
        'facebook' => ['label' => 'Facebook', 'icon' => 'facebook.svg', 'border' => 'group-hover:border-[#1877F2]/35'],
        'instagram' => ['label' => 'Instagram', 'icon' => 'instagram.svg', 'border' => 'group-hover:border-[#E4405F]/35'],
        'youtube' => ['label' => 'YouTube', 'icon' => 'youtube.svg', 'border' => 'group-hover:border-[#FF0000]/35'],
        'tiktok' => ['label' => 'TikTok', 'icon' => 'tiktok.svg', 'border' => 'group-hover:border-slate-400'],
        'telegram' => ['label' => 'Telegram', 'icon' => 'telegram.svg', 'border' => 'group-hover:border-[#229ED9]/35'],
        'whatsapp' => ['label' => 'WhatsApp', 'icon' => 'whatsapp.svg', 'border' => 'group-hover:border-[#25D366]/35'],
        'x' => ['label' => 'X', 'icon' => 'x.svg', 'border' => 'group-hover:border-slate-400'],
        'linkedin' => ['label' => 'LinkedIn', 'icon' => 'linkedin.svg', 'border' => 'group-hover:border-[#0A66C2]/35'],
    ];

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

<div class="min-h-screen bg-[#f7f6f1] text-slate-900">
    <section class="relative isolate overflow-hidden border-b border-emerald-950/10 bg-[#f4efe4]">
        <div class="absolute inset-0 -z-20 bg-[radial-gradient(circle_at_12%_15%,rgba(201,154,55,0.18),transparent_28%),radial-gradient(circle_at_88%_8%,rgba(5,98,76,0.18),transparent_34%),linear-gradient(135deg,#fffdf8_0%,#f3eee2_55%,#e6eee8_100%)]"></div>
        <div class="absolute inset-0 -z-10 opacity-[0.24]" style="background-image: radial-gradient(circle at 1px 1px, rgba(6,78,59,.22) 1px, transparent 0); background-size: 26px 26px;"></div>
        <div class="absolute -right-24 -top-28 -z-10 h-96 w-96 rounded-full border border-emerald-900/10"></div>
        <div class="absolute -right-8 -top-12 -z-10 h-72 w-72 rounded-full border border-amber-700/10"></div>

        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-8 lg:px-8 lg:py-10">
            <x-ui.breadcrumbs
                class="mb-6"
                :items="[
                    ['label' => __('Laman Utama'), 'url' => route('home'), 'icon' => 'home'],
                    ['label' => __('Institusi'), 'url' => route('institutions.index'), 'icon' => 'building', 'show_label' => true],
                ]"
            />

            <div
                data-institution-hero
                x-data="{
                    media: null,
                    panel: null,
                    title: null,
                    actions: null,
                    resizeObserver: null,
                    resizeHandler: null,
                    isFitting: false,
                    init() {
                        this.media = this.$el.querySelector('[data-institution-hero-media]');
                        this.panel = this.$el.querySelector('[data-institution-hero-panel]');
                        this.title = this.$el.querySelector('[data-institution-hero-title]');
                        this.actions = this.$el.querySelector('[data-institution-hero-actions]');

                        if (!this.media || !this.panel || !this.title || !this.actions) {
                            return;
                        }

                        this.resizeHandler = () => this.fitTitle();
                        this.resizeObserver = typeof ResizeObserver === 'function'
                            ? new ResizeObserver(this.resizeHandler)
                            : null;

                        this.resizeObserver?.observe(this.media);
                        this.resizeObserver?.observe(this.panel);
                        window.addEventListener('resize', this.resizeHandler, { passive: true });

                        this.$nextTick(() => {
                            this.fitTitle();
                            requestAnimationFrame(() => this.fitTitle());
                        });
                    },
                    fitTitle() {
                        if (this.isFitting || !this.media || !this.title || !this.actions) {
                            return;
                        }

                        this.isFitting = true;
                        const inlineTransition = this.title.style.getPropertyValue('transition');
                        const inlineTransitionPriority = this.title.style.getPropertyPriority('transition');

                        try {
                            this.title.style.setProperty('transition', 'none', 'important');
                            this.title.style.removeProperty('font-size');

                            if (!window.matchMedia('(min-width: 1024px)').matches) {
                                return;
                            }

                            const baseSize = Number.parseFloat(window.getComputedStyle(this.title).fontSize);
                            const minimumSize = 32;

                            if (!Number.isFinite(baseSize)) {
                                return;
                            }

                            let size = baseSize;
                            this.title.style.setProperty('font-size', `${size}px`, 'important');

                            while (
                                size > minimumSize
                                && this.actions.getBoundingClientRect().bottom > this.media.getBoundingClientRect().bottom
                            ) {
                                size -= 1;
                                this.title.style.setProperty('font-size', `${size}px`, 'important');
                            }
                        } finally {
                            if (inlineTransition) {
                                this.title.style.setProperty('transition', inlineTransition, inlineTransitionPriority);
                            } else {
                                this.title.style.removeProperty('transition');
                            }

                            this.isFitting = false;
                        }
                    },
                    destroy() {
                        this.resizeObserver?.disconnect();

                        if (this.resizeHandler) {
                            window.removeEventListener('resize', this.resizeHandler);
                        }
                    },
                }"
                class="mt-6 overflow-hidden rounded-[2rem] border border-white/80 bg-white/82 shadow-[0_30px_90px_-42px_rgba(6,78,59,0.42)] backdrop-blur-xl"
            >
                <div class="grid lg:grid-cols-[minmax(0,1.12fr)_minmax(24rem,0.88fr)]">
                    <div
                        data-institution-hero-media
                        class="relative aspect-video self-start overflow-hidden bg-gradient-to-br from-emerald-100 via-[#f4efe4] to-amber-100"
                    >
                        <div class="absolute inset-0 opacity-40" style="background-image: radial-gradient(circle at 1px 1px, rgba(7,91,72,.2) 1px, transparent 0); background-size: 20px 20px;"></div>
                        <img
                            src="{{ $institutionCoverUrl }}"
                            alt="{{ $institution->name }}"
                            class="absolute inset-0 h-full w-full object-cover"
                            loading="eager"
                        >
                        <div class="absolute inset-x-0 bottom-0 h-40 bg-gradient-to-t from-emerald-950/80 via-emerald-950/25 to-transparent"></div>
                    </div>

                    <div data-institution-hero-panel class="flex flex-col p-6 sm:p-8 lg:px-8 lg:pb-4 lg:pt-5">
                        <div class="flex flex-1 flex-col">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-[11px] font-black uppercase tracking-[0.24em] text-amber-700">
                                        {{ __('Institusi ilmu360°') }}
                                    </p>

                                    @if((string) $institution->status === 'verified')
                                        <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-100 px-2.5 py-1 text-[10px] font-bold text-emerald-800">
                                            <svg class="h-3.5 w-3.5 text-emerald-700" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.051l-7.5 9.75a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.897 3.896 6.976-9.07a.75.75 0 0 1 1.051-.142Z" clip-rule="evenodd" />
                                            </svg>
                                            {{ __('Disahkan') }}
                                        </span>
                                    @elseif((string) $institution->status === 'pending')
                                        <span class="inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-100 px-2.5 py-1 text-[10px] font-bold text-amber-800">
                                            <svg class="h-3.5 w-3.5 text-amber-600" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M12 2.25a.75.75 0 0 1 .66.4l9 15.75a.75.75 0 0 1-.66 1.125H3a.75.75 0 0 1-.66-1.125l9-15.75a.75.75 0 0 1 .66-.4Zm0 6a.75.75 0 0 1 .75.75v3.75a.75.75 0 0 1-1.5 0V9a.75.75 0 0 1 .75-.75Zm0 7.5a.9.9 0 1 0 0 1.8.9.9 0 0 0 0-1.8Z" clip-rule="evenodd" />
                                            </svg>
                                            {{ __('Belum disahkan') }}
                                        </span>
                                    @endif
                                </div>

                                <h1 data-institution-hero-title class="mt-3 max-w-3xl break-words font-heading text-4xl font-bold leading-[1.05] tracking-[-0.035em] text-emerald-950 sm:text-5xl lg:text-6xl">
                                    {{ $institution->name }}
                                </h1>

                                @if($locationString !== '')
                                    <p class="flex items-start gap-2 text-sm leading-6 text-slate-600 sm:text-base">
                                        <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                                        </svg>
                                        <span>{{ $locationString }}</span>
                                    </p>
                                @endif

                                @if($institution->description)
                                    <div class="mt-4">
                                        <div class="mt-2 h-24 max-h-24 overflow-y-auto overscroll-contain pr-4 [scrollbar-color:#a7d5c7_transparent] [scrollbar-width:thin] prose prose-slate max-w-none leading-8 prose-headings:font-heading prose-headings:text-emerald-950 prose-a:text-emerald-700 prose-strong:text-slate-900">
                                            {!! $institution->description !!}
                                        </div>
                                    </div>
                                @endif

                            </div>

                            <div data-institution-hero-actions class="mt-auto pt-6">
                                <div class="grid grid-cols-2 gap-3 border-t border-emerald-950/10 pt-4 sm:max-w-md">
                                    <div class="rounded-2xl border border-emerald-100 bg-emerald-50/70 p-3 text-center">
                                        <p class="font-heading text-2xl font-bold text-emerald-950">{{ number_format($upcomingTotal) }}</p>
                                        <p class="mt-1 text-[10px] font-semibold text-emerald-700">{{ __('Majlis akan datang') }}</p>
                                    </div>
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-3 text-center">
                                        <p class="font-heading text-2xl font-bold text-emerald-950">{{ number_format($pastTotal) }}</p>
                                        <p class="mt-1 text-[10px] font-semibold text-slate-500">{{ __('Majlis lepas') }}</p>
                                    </div>
                                </div>

                                <div class="mt-4 flex flex-row flex-wrap gap-3">
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
                                            <span class="hidden sm:inline">{{ __('Mengikuti Institusi') }}</span>
                                        @else
                                            <span class="sm:hidden">{{ __('Ikuti') }}</span>
                                            <span class="hidden sm:inline">{{ __('Ikuti Institusi') }}</span>
                                        @endif
                                    </span>
                                    <span wire:loading wire:target="toggleFollow">{{ __('Memproses...') }}</span>
                                </button>

                                <a
                                    href="#institution-share-panel"
                                    class="inline-flex h-12 min-w-0 flex-1 items-center justify-center gap-2 rounded-2xl border border-emerald-200 bg-white px-6 text-sm font-bold text-emerald-800 transition hover:-translate-y-0.5 hover:border-emerald-300 hover:bg-emerald-50 sm:flex-none"
                                >
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5Zm9.566-3.75a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5Zm0 14.25a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5ZM9.164 8.197l5.672-3.144m-5.672 5.75 5.672 3.144" />
                                    </svg>
                                    <span class="sm:hidden">{{ __('Kongsi') }}</span>
                                    <span class="hidden sm:inline">{{ __('Kongsi Profil') }}</span>
                                </a>

                                @if(auth()->user()?->hasAnyRole(['super_admin', 'admin']))
                                    <a
                                        href="{{ \App\Filament\Resources\Institutions\InstitutionResource::getUrl('edit', ['record' => $institution], panel: 'admin') }}"
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
        </div>
    </section>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="min-w-0 space-y-8">
                <section class="scroll-reveal reveal-up revealed space-y-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.22em] text-amber-700">{{ __('Jadual Institusi') }}</p>
                            <h2 class="mt-1 font-heading text-3xl font-bold text-emerald-950">{{ __('Majlis Akan Datang') }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('Senarai majlis akan datang dan yang telah berlangsung.') }}</p>
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
                                data-signal-component="institution_detail_upcoming_events"
                                data-signal-control="date_filter"
                                class="w-max min-w-max"
                            >
                                @foreach([
                                    'all' => __('Semua'),
                                    'today' => __('Hari ini'),
                                    'tomorrow' => __('Esok'),
                                    'this_week' => __('Minggu ini'),
                                    'this_weekend' => __('Hujung minggu'),
                                    'this_month' => __('Bulan ini'),
                                    'next_week' => __('Minggu depan'),
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
                                    aria-label="{{ __('Pilih julat tarikh') }}"
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
                                <flux:heading size="lg" class="text-emerald-950!">{{ __('Pilih julat tarikh') }}</flux:heading>
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
                                        ? __('Ikuti institusi ini untuk mengetahui apabila jadual majlis baharu diterbitkan.')
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
            </div>

            <aside class="space-y-6 lg:sticky lg:top-24 lg:self-start">
                @if($addressLines['street'] || $addressLines['locality'] || $addressLines['regional'] || $googleMapsEmbedUrl)
                    <section class="scroll-reveal reveal-right revealed rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-[10px] font-black uppercase tracking-[0.22em] text-amber-700">{{ __('Lokasi Institusi') }}</p>
                        <div class="mt-4 grid gap-5">
                            @if($addressLines['street'] || $addressLines['locality'] || $addressLines['regional'])
                                <div>
                                    <h2 class="font-heading text-xl font-bold text-emerald-950">{{ __('Alamat') }}</h2>
                                    <div class="mt-3 space-y-1 text-sm leading-6 text-slate-600">
                                        @if($addressLines['street'])<p>{{ $addressLines['street'] }}</p>@endif
                                        @if($addressLines['locality'])<p>{{ $addressLines['locality'] }}</p>@endif
                                        @if($addressLines['regional'])<p>{{ $addressLines['regional'] }}</p>@endif
                                    </div>
                                </div>
                            @endif

                            @if($googleMapsEmbedUrl)
                                <div>
                                    <h2 class="font-heading text-xl font-bold text-emerald-950">{{ __('Peta') }}</h2>
                                    <div class="mt-3 overflow-hidden rounded-2xl border border-slate-200">
                                        <iframe src="{{ $googleMapsEmbedUrl }}" class="h-56 w-full" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                                    </div>
                                    <div class="mt-3 {{ $wazeUrl && $googleMapsBrowseUrl ? 'grid grid-cols-2 gap-3' : 'flex justify-center' }}">
                                        @if($wazeUrl)
                                            <a href="{{ $wazeUrl }}" target="_blank" rel="noopener noreferrer" class="group inline-flex min-w-0 items-center justify-center gap-2 rounded-2xl border border-sky-200 bg-sky-50 px-3 py-2.5 text-xs font-bold text-sky-900 shadow-sm shadow-sky-900/5 transition hover:-translate-y-0.5 hover:border-sky-300 hover:bg-sky-100 hover:shadow-md focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-sky-500/15">
                                                <img src="{{ asset('images/waze-app-icon-seeklogo.svg') }}" alt="" class="h-8 w-8 shrink-0 object-contain transition group-hover:scale-110" loading="lazy">
                                                <span class="truncate">{{ __('Waze') }}</span>
                                            </a>
                                        @endif
                                        @if($googleMapsBrowseUrl)
                                            <a href="{{ $googleMapsBrowseUrl }}" target="_blank" rel="noopener noreferrer" class="group inline-flex min-w-0 items-center justify-center gap-2 rounded-2xl border border-emerald-200 bg-white px-3 py-2.5 text-xs font-bold text-slate-800 shadow-sm shadow-emerald-900/5 transition hover:-translate-y-0.5 hover:border-emerald-300 hover:bg-emerald-50 hover:shadow-md focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/15">
                                                <img src="{{ asset('images/google-maps.svg') }}" alt="" class="h-8 w-8 shrink-0 object-contain transition group-hover:scale-110" loading="lazy">
                                                <span class="truncate">{{ __('Google Maps') }}</span>
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    </section>
                @endif

                @if($publicContacts->isNotEmpty())
                    <section class="scroll-reveal reveal-right revealed rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-[10px] font-black uppercase tracking-[0.22em] text-amber-700">{{ __('Hubungi Institusi') }}</p>
                        <h2 class="mt-1 font-heading text-xl font-bold text-emerald-950">{{ __('Maklumat Hubungan') }}</h2>
                        <div class="mt-4 space-y-2">
                            @foreach($publicContacts as $contact)
                                @php
                                    $contactType = strtolower((string) $contact->type);
                                    $contactHref = $formatContactHref($contact);
                                    $presentation = $contactPresentation[$contactType] ?? [
                                        'label' => \Illuminate\Support\Str::headline($contactType),
                                        'icon' => 'link',
                                        'tone' => 'bg-slate-50 text-slate-600 ring-slate-100',
                                    ];
                                @endphp
                                <div class="group rounded-2xl border border-slate-200/90 bg-slate-50/45 p-3 transition hover:border-emerald-200 hover:bg-white hover:shadow-sm">
                                    <div class="flex items-center gap-3">
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl ring-1 {{ $presentation['tone'] }}" aria-hidden="true">
                                            @if($presentation['icon'] === 'phone')
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372a1.125 1.125 0 0 0-.852-1.093l-4.423-1.106a1.125 1.125 0 0 0-1.173.417l-.97 1.185a1.125 1.125 0 0 1-1.21.337 12.04 12.04 0 0 1-7.408-7.408 1.125 1.125 0 0 1 .337-1.21l1.185-.97a1.125 1.125 0 0 0 .417-1.173L6.597 2.689A1.125 1.125 0 0 0 5.504 1.837H4.125A2.25 2.25 0 0 0 1.875 4.087v2.663Z" /></svg>
                                            @elseif($presentation['icon'] === 'email')
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5A2.25 2.25 0 0 1 19.5 19.5h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0l-7.5-4.615A2.25 2.25 0 0 1 2.25 6.993V6.75" /></svg>
                                            @elseif($presentation['icon'] === 'whatsapp')
                                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><path d="M20.52 3.48A11.82 11.82 0 0 0 12.08 0C5.55 0 .24 5.3.24 11.84c0 2.09.55 4.13 1.59 5.93L.14 24l6.37-1.67a11.84 11.84 0 0 0 5.57 1.42h.01c6.53 0 11.84-5.31 11.84-11.84 0-3.17-1.23-6.14-3.41-8.43ZM12.09 21.7h-.01a9.84 9.84 0 0 1-5.02-1.37l-.36-.21-3.78.99 1.01-3.68-.23-.38a9.85 9.85 0 1 1 8.39 4.65Zm5.41-7.39c-.3-.15-1.77-.87-2.05-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.65.07-.3-.15-1.27-.47-2.42-1.5-.9-.8-1.5-1.78-1.67-2.08-.17-.3-.02-.46.13-.61.14-.14.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.07-.15-.67-1.61-.92-2.21-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.49s1.07 2.89 1.22 3.09c.15.2 2.1 3.21 5.09 4.5.71.31 1.26.49 1.7.63.71.23 1.36.2 1.87.12.57-.09 1.77-.72 2.02-1.42.25-.7.25-1.3.17-1.42-.07-.13-.27-.2-.57-.35Z" /></svg>
                                            @else
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.5-1.5m8.122-3.88a4.5 4.5 0 0 1 0-6.364l1.5-1.5a4.5 4.5 0 0 1 6.364 6.364l-4.5 4.5" /></svg>
                                            @endif
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">{{ $presentation['label'] }}</p>
                                            @if($contactHref)<a href="{{ $contactHref }}" class="mt-1 block break-all text-sm font-semibold text-slate-700 transition group-hover:text-emerald-800">{{ $contact->value }}</a>@else<p class="mt-1 break-all text-sm font-semibold text-slate-700">{{ $contact->value }}</p>@endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if($socialLinks->isNotEmpty())
                    <section class="scroll-reveal reveal-right revealed rounded-[1.5rem] border border-emerald-100 bg-white p-5 shadow-sm">
                        <p class="text-[10px] font-black uppercase tracking-[0.22em] text-amber-700">{{ __('Pautan Institusi') }}</p>
                        <h2 class="mt-1 font-heading text-xl font-bold text-emerald-950">{{ __('Media Sosial Rasmi') }}</h2>
                        <div class="mt-5 grid gap-3">
                            @foreach($socialLinks as $social)
                                @php
                                    $platform = strtolower((string) $social->platform);
                                    $presentation = $socialPresentation[$platform] ?? ['label' => \Illuminate\Support\Str::headline($platform), 'icon' => 'link.svg', 'border' => 'group-hover:border-emerald-300'];
                                    $handle = trim((string) ($social->handle ?? ''));
                                    $handleLabel = $handle !== '' ? (str_starts_with($handle, '@') ? $handle : '@'.$handle) : __('Lihat profil rasmi');
                                @endphp
                                <a href="{{ $social->profileUrl() ?? $social->url }}" target="_blank" rel="noopener noreferrer" class="group flex min-w-0 items-center gap-3 rounded-2xl border border-slate-200/90 bg-slate-50/45 px-3 py-3 transition hover:bg-white hover:text-emerald-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/10 {{ $presentation['border'] }}">
                                    <img src="{{ asset('storage/social-media-icons/'.$presentation['icon']) }}" alt="" class="h-10 w-10 shrink-0 object-contain" loading="lazy">
                                    <span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold text-slate-800 group-hover:text-emerald-800">{{ $presentation['label'] }}</span><span class="mt-0.5 block truncate text-xs text-slate-500">{{ $handleLabel }}</span></span>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if($persons->isNotEmpty())
                    <section class="scroll-reveal reveal-right revealed rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-[10px] font-black uppercase tracking-[0.22em] text-amber-700">{{ __('Komuniti Institusi') }}</p>
                        <h2 class="mt-1 font-heading text-xl font-bold text-emerald-950">{{ __('Penceramah') }}</h2>
                        <ul class="mt-4 space-y-4">
                            @foreach($persons as $person)
                                <li class="flex items-center gap-3 rounded-2xl border border-slate-200/90 bg-slate-50/45 p-3">
                                    <img
                                        src="{{ $person->public_avatar_url }}"
                                        alt="{{ $person->formatted_name !== '' ? $person->formatted_name : $person->name }}"
                                        class="h-12 w-12 rounded-full object-cover"
                                        loading="lazy"
                                    >
                                    <div class="min-w-0">
                                        <p class="font-semibold text-slate-900">{{ $person->name }}</p>
                                        @if(filled($person->pivot?->position))
                                            <p class="text-sm text-slate-500">{{ $person->pivot->position }}</p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if($spaces->isNotEmpty())
                    <section class="scroll-reveal reveal-right revealed rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-[10px] font-black uppercase tracking-[0.22em] text-amber-700">{{ __('Kemudahan') }}</p>
                        <h2 class="mt-1 font-heading text-xl font-bold text-emerald-950">{{ __('Ruang') }}</h2>
                        <ul class="mt-4 space-y-3 text-sm text-slate-700">
                            @foreach($spaces as $space)
                                @php $effectiveCapacity = $space->effectiveCapacity(); @endphp
                                <li class="flex items-center justify-between gap-4">
                                    <span class="font-medium text-slate-900">{{ $space->name }}</span>
                                    @if($effectiveCapacity)
                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ $effectiveCapacity }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @php
                    $sortedDonationChannels = $donationChannels->where('status', 'verified')->sortByDesc('is_default')->values();
                @endphp
                @if($sortedDonationChannels->isNotEmpty())
                    <section
                        x-data="{ copiedId: null, qrModalUrl: null, qrModalAlt: '', async copy(text, id) { try { await navigator.clipboard.writeText(text); this.copiedId = id; setTimeout(() => { if (this.copiedId === id) this.copiedId = null; }, 2200); } catch (e) {} } }"
                        class="scroll-reveal reveal-right revealed rounded-[1.5rem] border border-emerald-100 bg-white p-5 shadow-sm"
                    >
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.22em] text-amber-700">{{ __('Sokongan') }}</p>
                            <h2 class="mt-1 font-heading text-xl font-bold text-emerald-950">{{ __('Sumbangan') }}</h2>
                        </div>

                        <div class="mt-4 space-y-3">
                            @foreach($sortedDonationChannels as $channel)
                                @php
                                    $isDefault = (bool) $channel->is_default;
                                    $method = (string) $channel->method;
                                    $methodLabel = match ($method) {
                                        'bank_account' => __('Akaun Bank'),
                                        'duitnow' => 'DuitNow',
                                        'ewallet' => __('E-Dompet'),
                                        default => $channel->method_display ?: \Illuminate\Support\Str::headline($method),
                                    };
                                    $methodTone = match ($method) {
                                        'bank_account' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
                                        'duitnow' => 'border-sky-200 bg-sky-50 text-sky-800',
                                        'ewallet' => 'border-amber-200 bg-amber-50 text-amber-800',
                                        default => 'border-slate-200 bg-slate-50 text-slate-700',
                                    };
                                    $copyValue = match ($method) {
                                        'bank_account' => (string) $channel->account_number,
                                        'duitnow' => (string) $channel->duitnow_value,
                                        'ewallet' => (string) ($channel->ewallet_handle ?: $channel->ewallet_qr_payload),
                                        default => (string) ($channel->account_number ?: $channel->duitnow_value ?: $channel->ewallet_handle),
                                    };
                                    $copyLabel = match ($method) {
                                        'bank_account' => __('No. Akaun'),
                                        'duitnow' => __('ID DuitNow'),
                                        'ewallet' => __('ID E-Dompet'),
                                        default => __('No. Akaun'),
                                    };
                                    $duitnowTypeLabel = $channel->duitnow_type ? \Illuminate\Support\Str::headline((string) $channel->duitnow_type) : null;
                                    $ewalletProviderLabel = $channel->ewallet_provider ? match (strtolower((string) $channel->ewallet_provider)) {
                                        'tng' => "Touch 'n Go",
                                        'grab' => 'GrabPay',
                                        'shopee' => 'ShopeePay',
                                        'boost' => 'Boost',
                                        default => \Illuminate\Support\Str::headline((string) $channel->ewallet_provider),
                                    } : null;
                                    $bankDisplay = $channel->bank_name ?: $channel->bank_code;
                                    $qrThumb = $channel->getFirstMedia('qr')?->getAvailableUrl(['thumb']) ?: $channel->getFirstMediaUrl('qr');
                                    $qrFull = $channel->getFirstMediaUrl('qr') ?: $qrThumb;
                                @endphp
                                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[10px] font-black uppercase tracking-wide {{ $methodTone }}">
                                            @if($method === 'bank_account')
                                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12 4 4.5h16L21.75 12M3 12h18M4.5 12V19.5A2.25 2.25 0 0 0 6.75 21.75h10.5A2.25 2.25 0 0 0 19.5 19.5V12M9 16.5h6" /></svg>
                                            @elseif($method === 'duitnow')
                                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12 12 11.25 12 9.75V6m0 0c-1.5 0-3 .75-3 2.25 0 1.5 1.5 2.25 3 2.25s3-.75 3-2.25S13.5 6 12 6Z" /></svg>
                                            @else
                                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 12a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 12ZM3 12v2.25A2.25 2.25 0 0 0 5.25 16.5h13.5A2.25 2.25 0 0 0 21 14.25V12M16.5 10.5h.008v.008H16.5v-.008Z" /></svg>
                                            @endif
                                            {{ $methodLabel }}
                                        </span>
                                        @if($isDefault)
                                            <span class="inline-flex items-center rounded-full bg-emerald-700 px-2 py-1 text-[10px] font-bold text-white">{{ __('Utama') }}</span>
                                        @endif
                                        @if(filled($channel->label))
                                            <span class="text-[11px] font-semibold text-slate-500">• {{ $channel->label }}</span>
                                        @endif
                                    </div>

                                    <div class="mt-3 flex items-start gap-4">
                                        <div class="min-w-0 flex-1 space-y-2">
                                            <p class="truncate font-semibold leading-5 text-slate-900" title="{{ $channel->recipient }}">{{ $channel->recipient }}</p>

                                            @if($method === 'bank_account' && filled($bankDisplay))
                                                <p class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-slate-600">
                                                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-md bg-white ring-1 ring-slate-200">
                                                        <svg class="h-3 w-3 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12 4 4.5h16L21.75 12M3 12h18M4.5 12V19.5A2.25 2.25 0 0 0 6.75 21.75h10.5A2.25 2.25 0 0 0 19.5 19.5V12M9 16.5h6" /></svg>
                                                    </span>
                                                    {{ $bankDisplay }}
                                                </p>
                                            @endif

                                            @if($method === 'duitnow' && filled($channel->duitnow_value))
                                                <p class="text-xs font-semibold text-slate-600">
                                                    DuitNow{{ $duitnowTypeLabel ? ' • '.$duitnowTypeLabel : '' }}
                                                </p>
                                            @endif

                                            @if($method === 'ewallet' && filled($ewalletProviderLabel))
                                                <p class="text-xs font-semibold text-slate-600">{{ $ewalletProviderLabel }}</p>
                                            @endif

                                            @if(filled($copyValue))
                                                <div class="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 shadow-sm">
                                                    <div class="min-w-0 flex-1">
                                                        <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">{{ $copyLabel }}</p>
                                                        <p class="truncate font-mono text-sm font-bold tracking-wide text-slate-900">{{ $copyValue }}</p>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        @click="copy(@js($copyValue), @js((string) $channel->id))"
                                                        class="inline-flex h-8 shrink-0 items-center justify-center gap-1.5 rounded-lg border px-3 text-xs font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600/20"
                                                        :class="copiedId === @js((string) $channel->id) ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-700 hover:bg-white'"
                                                    >
                                                        <svg x-show="copiedId !== @js((string) $channel->id)" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75A1.125 1.125 0 0 1 3.75 20.625V7.875c0-.621.504-1.125 1.125-1.125H6.75m9 9.75h.008v.008H15.75v-.008Zm0 0V9.75a2.25 2.25 0 0 0-2.25-2.25H9.75a2.25 2.25 0 0 0-2.25 2.25v9.75A2.25 2.25 0 0 0 9.75 21h3.75A2.25 2.25 0 0 0 15.75 18Z" /></svg>
                                                        <svg x-show="copiedId === @js((string) $channel->id)" x-cloak class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.051l-7.5 9.75a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.897 3.896 6.976-9.07a.75.75 0 0 1 1.051-.142Z" clip-rule="evenodd" /></svg>
                                                        <span x-text="copiedId === @js((string) $channel->id) ? @js(__('Disalin!')) : @js(__('Salin'))"></span>
                                                    </button>
                                                </div>
                                            @endif

                                            @if(filled($channel->reference_note))
                                                <p class="text-xs leading-5 text-slate-500">{{ __('Rujukan') }}: <span class="font-semibold text-slate-700">{{ $channel->reference_note }}</span></p>
                                            @endif
                                        </div>

                                        @if(filled($qrThumb))
                                            <button type="button" class="group relative shrink-0 transition-transform active:scale-95" @click="qrModalUrl=@js($qrFull); qrModalAlt=@js($channel->label ?: $channel->recipient)">
                                                <img
                                                    src="{{ $qrThumb }}"
                                                    alt="{{ $channel->label ?: $channel->recipient }}"
                                                    class="h-20 w-20 rounded-2xl border border-slate-200 bg-white object-cover p-1 shadow-sm group-hover:border-emerald-300"
                                                    loading="lazy"
                                                >
                                                <span class="pointer-events-none absolute inset-0 grid place-items-center rounded-2xl bg-emerald-950/0 transition group-hover:bg-emerald-950/5"></span>
                                                <span class="pointer-events-none absolute bottom-1 left-1 right-1 rounded-lg bg-white/95 px-1 py-0.5 text-center text-[9px] font-bold leading-none text-slate-700 shadow-sm">{{ __('Imbas QR') }}</span>
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div x-show="qrModalUrl" x-cloak x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @click.self="qrModalUrl=null" @keydown.escape.window="qrModalUrl=null" style="display: none;">
                            <div class="relative w-full max-w-sm rounded-[1.5rem] bg-white p-4 shadow-2xl">
                                <button type="button" @click="qrModalUrl=null" class="absolute -right-3 -top-3 grid h-8 w-8 place-items-center rounded-full bg-white text-slate-600 shadow-lg ring-1 ring-slate-200 hover:text-slate-900" aria-label="{{ __('Tutup') }}">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                </button>
                                <img x-bind:src="qrModalUrl" x-bind:alt="qrModalAlt" class="mx-auto max-h-[70vh] w-full rounded-2xl border border-slate-200 bg-white object-contain p-2">
                                <p class="mt-3 text-center text-sm font-semibold text-slate-700" x-text="qrModalAlt"></p>
                                <p class="mt-1 text-center text-xs text-slate-500">{{ __('Imbas kod QR ini dengan aplikasi bank / e-dompet anda.') }}</p>
                            </div>
                        </div>
                    </section>
                @endif

                <x-public-record-feedback
                    share-panel-id="institution-share-panel"
                    :subject-type="$institutionRouteSegment"
                    :subject-id="$institution->slug"
                    :share-data="$shareData"
                    :share-links="$shareLinks"
                />

                @if(! $this->hasAdminOrOwnerMember)
                    <section class="rounded-[1.5rem] border border-amber-200 bg-gradient-to-br from-amber-50 via-white to-emerald-50/60 p-5 shadow-sm">
                        <p class="text-[10px] font-black uppercase tracking-[0.22em] text-amber-700">{{ __('Membership') }}</p>
                        <h2 class="mt-1 font-heading text-xl font-bold text-emerald-950">{{ __('Claim Membership') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            {{ __('Claim membership for this :subject', ['subject' => \Illuminate\Support\Str::lower(__('Institution'))]) }}
                        </p>
                        <p class="mt-3 text-xs leading-5 text-slate-500">
                            {{ __('Your proof is reviewed first. Access is only added after an admin or moderator approves the claim.') }}
                        </p>
                        <a
                            href="{{ route('membership-applications.create', ['subjectType' => \App\Enums\MemberSubjectType::Institution->publicRouteSegment(), 'subjectId' => $institution->getKey()]) }}"
                            wire:navigate
                            class="mt-4 inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-emerald-800 px-4 text-sm font-bold text-white transition hover:-translate-y-0.5 hover:bg-emerald-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/15"
                        >
                            {{ __('Claim Membership') }}
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5 5 5-5 5" />
                            </svg>
                        </a>
                    </section>
                @endif

                <x-sidebar-inspiration />
            </aside>
        </div>
    </div>
</div>
