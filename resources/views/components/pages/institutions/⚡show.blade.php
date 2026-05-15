<?php

use App\Enums\DawahShareOutcomeType;
use App\Models\Event;
use App\Models\Institution;
use App\Services\ShareTrackingService;
use App\Support\Auth\IntendedRedirect;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

new class extends Component
{
    public Institution $institution;

    public int $upcomingPerPage = 6;

    public int $pastPerPage = 6;

    public bool $isFollowing = false;

    public function mount(Institution $institution): void
    {
        if ($institution->status !== 'verified' && ! auth()->user()?->hasAnyRole(['super_admin', 'moderator'])) {
            abort(404);
        }

        $this->institution = $institution->load([
            'media',
            'address.state',
            'address.city',
            'address.district',
            'address.subdistrict',
            'address.country',
            'contacts',
            'socialMedia',
            'donationChannels.media',
            'speakers' => fn ($q) => $q->where('status', 'verified')->orderByPivot('is_primary', 'desc')->limit(12),
            'speakers.media',
            'spaces' => fn ($q) => $q->where('is_active', true),
            'languages',
        ]);

        $this->isFollowing = auth()->user()?->isFollowing($institution) ?? false;
    }

    public function toggleFollow(): void
    {
        $user = auth()->user();

        if (! $user) {
            $this->redirect(IntendedRedirect::loginUrl(route('institutions.show', $this->institution)), navigate: true);

            return;
        }

        if ($this->isFollowing) {
            $user->unfollow($this->institution);
            $this->isFollowing = false;
        } else {
            $user->follow($this->institution);
            $this->isFollowing = true;
            app(ShareTrackingService::class)->recordOutcome(
                type: DawahShareOutcomeType::InstitutionFollow,
                outcomeKey: 'institution_follow:user:'.$user->id.':institution:'.$this->institution->id,
                subject: $this->institution,
                actor: $user,
                request: request(),
                metadata: [
                    'institution_id' => $this->institution->id,
                ],
            );
        }
    }

    public function loadMoreUpcoming(): void
    {
        $this->upcomingPerPage += 6;
    }

    public function loadMorePast(): void
    {
        $this->pastPerPage += 6;
    }

    /**
     * @return Collection<int, Event>
     */
    public function getUpcomingEventsProperty(): Collection
    {
        return $this->institution->events()
            ->active()
            ->where('starts_at', '>=', now())
            ->with([
                'venue.address.state',
                'venue.address.district',
                'venue.address.subdistrict',
                'speakers.media',
                'keyPeople.speaker',
                'references',
                'media',
            ])
            ->orderBy('starts_at', 'asc')
            ->take($this->upcomingPerPage)
            ->get();
    }

    public function getUpcomingTotalProperty(): int
    {
        return $this->institution->events()
            ->active()
            ->where('starts_at', '>=', now())
            ->count();
    }

    /**
     * @return Collection<int, Event>
     */
    public function getPastEventsProperty(): Collection
    {
        return $this->institution->events()
            ->active()
            ->where('starts_at', '<', now())
            ->with([
                'venue.address.state',
                'venue.address.district',
                'venue.address.subdistrict',
                'speakers.media',
                'keyPeople.speaker',
                'references',
                'media',
            ])
            ->orderBy('starts_at', 'desc')
            ->take($this->pastPerPage)
            ->get();
    }

    public function getPastTotalProperty(): int
    {
        return $this->institution->events()
            ->active()
            ->where('starts_at', '<', now())
            ->count();
    }

    public function rendering($view): void
    {
        $view->title($this->institution->name.' - '.config('app.name'));
    }
};

?>

@section('title', $this->institution->name . ' - ' . config('app.name'))
@section('meta_description', \Illuminate\Support\Str::limit(trim(strip_tags((string) $this->institution->description)) ?: __('Lihat profil, lokasi, saluran sumbangan, dan majlis akan datang oleh :name di :app.', ['name' => $this->institution->name, 'app' => config('app.name')]), 160))
@section('meta_robots', $this->institution->status === 'verified' ? 'index, follow' : 'noindex, nofollow')
@section('og_url', route('institutions.show', $this->institution))
@section('og_image', $this->institution->public_image_url)
@section('og_image_alt', __('Profil institusi :name', ['name' => $this->institution->name]))

<style>
    @media (max-width: 1023px) {
        .institution-main-column {
            order: 1 !important;
        }

        .institution-sidebar-column {
            order: 2 !important;
        }
    }
</style>

@php
    $institution = $this->institution;
    $upcomingEvents = $this->upcomingEvents;
    $pastEvents = $this->pastEvents;
    $upcomingTotal = $this->upcomingTotal;
    $pastTotal = $this->pastTotal;

    $qrModalChannels = $institution->donationChannels->filter(fn($channel) => $channel->hasMedia('qr'))->map(function($channel) {
        return [
            'id' => $channel->id,
            'label' => $channel->label ?: $channel->recipient,
            'original_url' => $channel->getFirstMediaUrl('qr'),
        ];
    })->values();

    $coverUrl = $institution->public_cover_url;
    $logoUrl = $institution->public_logo_url;
    $heroInstitutionImageUrl = $institution->public_image_url;
    $sharePreviewHasCover = $institution->hasMedia('cover');
    $sharePreviewImage = $institution->public_image_url;
    $gallery = $institution->getMedia('gallery');
    $speakers = $institution->speakers;
    $spaces = $institution->spaces;
    $donationChannels = $institution->donationChannels;
    $socialLinks = $institution->socialMedia
        ->filter(fn($social) => filled($social->resolved_url) && filled($social->platform))
        ->mapWithKeys(fn($social) => [strtolower((string) $social->platform) => $social])
        ->values();
    $contacts = $institution->contacts->where('is_public', true);
    $languages = $institution->languages;
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

    $showPendingStatusNotice = $institution->status === 'pending';
    $eventStatusNoticeEvents = $upcomingEvents->concat($pastEvents);
    $showPendingEventStatusNotice = $eventStatusNoticeEvents->contains(fn (\App\Models\Event $event): bool => $event->status instanceof \App\States\EventStatus\Pending);
    $showCancelledEventStatusNotice = $eventStatusNoticeEvents->contains(fn (\App\Models\Event $event): bool => $event->status instanceof \App\States\EventStatus\Cancelled);
    // Location
    $address = $institution->addressModel;
    $formatAddressHierarchy = static function ($addressModel): string {
        $parts = \App\Support\Location\AddressHierarchyFormatter::parts($addressModel);

        return $parts === [] ? '-' : implode(', ', $parts);
    };
    $locationString = $formatAddressHierarchy($address);
    $locationHierarchyParts = $address ? \App\Support\Location\AddressHierarchyFormatter::parts($address) : [];
    $streetAddressLine = implode(', ', array_filter([
        $address?->line1,
        $address?->line2,
    ]));
    if ($locationHierarchyParts !== []) {
        $localityAddressLine = implode(', ', array_filter([
            array_shift($locationHierarchyParts),
            $address?->postcode,
        ]));
        $regionalAddressLine = $locationHierarchyParts === [] ? '' : implode(', ', $locationHierarchyParts);
    } else {
        $localityAddressLine = implode(', ', array_filter([
            $address?->city?->name,
            $address?->postcode,
        ]));
        $regionalAddressLine = filled($address?->state?->name) ? (string) $address->state->name : '';
    }
    $mapQuery = implode(', ', array_filter([
        $institution->name,
        $address?->line1,
        $address?->line2,
        $address?->city?->name,
        $address?->state?->name,
        $address?->country?->name,
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
    if (!filled($normalizedMapQuery) && filled($mapQuery)) {
        $normalizedMapQuery = $mapQuery;
    }

    $hasPhysicalAddress = $address && ($address->line1 || $address->city?->name || $address->state?->name);
    $googleMapsEmbedUrl = null;
    if (filled($address?->google_maps_url) && filled($normalizedMapQuery)) {
        $googleMapsEmbedUrl = 'https://www.google.com/maps?q=' . urlencode((string) $normalizedMapQuery) . '&output=embed';
    }
    $wazeUrl = filled($address?->waze_url) ? (string) $address->waze_url : null;
    // Institution type label
    $typeLabel = $institution->type?->getLabel();

    // Event type label resolver
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

    // Event location helper — only show an explicit event venue on institution cards.
    $resolveVenueLocation = static function (\App\Models\Event $event) use ($formatAddressHierarchy): string {
        $venueName = $event->venue?->name;
        $address = $event->venue?->addressModel;
        $hierarchy = $formatAddressHierarchy($address);
        $hierarchy = $hierarchy === '-' ? '' : $hierarchy;

        if (is_string($venueName) && $venueName !== '') {
            return $hierarchy !== ''
                ? $venueName . ' • ' . $hierarchy
                : $venueName;
        }

        return $hierarchy;
    };

    $joinEventPeopleNames = static function (\Illuminate\Support\Collection $names): string {
        return $names->join(', ', ' dan ');
    };

    /**
     * @return array{items: \Illuminate\Support\Collection<int, array{name: string, url: string}>, overflow: int}
     */
    $resolveEventSpeakerAvatarStack = static function (\App\Models\Event $event): array {
        $avatars = $event->speakers
            ->map(function (\App\Models\Speaker $speaker): array {
                return [
                    'name' => trim((string) ($speaker->formatted_name !== '' ? $speaker->formatted_name : $speaker->name)),
                    'url' => $speaker->public_avatar_url,
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

    /**
     * @return array{speakers: string, roles: string}
     */
    $resolveEventPeople = static function (\App\Models\Event $event) use ($joinEventPeopleNames): array {
        $speakerSummary = $event->speakers
            ->map(fn (\App\Models\Speaker $speaker): string => trim((string) ($speaker->formatted_name !== '' ? $speaker->formatted_name : $speaker->name)))
            ->filter(fn (string $name): bool => $name !== '')
            ->unique()
            ->values();

        $speakerSummary = $speakerSummary->isNotEmpty()
            ? $joinEventPeopleNames($speakerSummary)
            : '';

        $roleSummary = $event->keyPeople
            ->filter(function (\App\Models\EventKeyPerson $keyPerson): bool {
                $role = $keyPerson->role;
                $role = $role instanceof \App\Enums\EventKeyPersonRole
                    ? $role
                    : \App\Enums\EventKeyPersonRole::tryFrom((string) $role);

                return $keyPerson->is_public && $role !== \App\Enums\EventKeyPersonRole::Speaker;
            })
            ->groupBy(function (\App\Models\EventKeyPerson $keyPerson): string {
                $role = $keyPerson->role;

                return $role instanceof \App\Enums\EventKeyPersonRole
                    ? $role->value
                    : (string) $role;
            })
            ->map(function (\Illuminate\Support\Collection $keyPeople, string $role) use ($joinEventPeopleNames): ?string {
                $names = $keyPeople
                    ->map(fn (\App\Models\EventKeyPerson $keyPerson): string => trim((string) $keyPerson->display_name))
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
            'speakers' => $speakerSummary,
            'roles' => $roleSummary,
        ];
    };

    $resolveEventTimeDisplay = static function (\App\Models\Event $event): string {
        return $event->timing_display !== ''
            ? $event->timing_display
            : \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'h:i A');
    };

    $resolveEventEndTimeDisplay = static function (\App\Models\Event $event): string {
        return \App\Support\Timezone\UserDateTimeFormatter::format($event->ends_at, 'h:i A');
    };

    // Calendar data: map events to dates for the calendar view
    $calendarEvents = $upcomingEvents->groupBy(fn($e) => \App\Support\Timezone\UserDateTimeFormatter::format($e->starts_at, 'Y-m-d'))->map(fn($group) => $group->map(function (\App\Models\Event $e) use ($resolveEventTypeLabel) {
        $typeLabel = $resolveEventTypeLabel($e->event_type);
        $formatValue = $e->event_format?->value ?? $e->event_format;

        return [
            'id' => $e->id,
            'title' => (string) str($e->title)
                ->replace($typeLabel . ': ', '')
                ->replace($typeLabel . ' - ', '')
                ->replace(' (' . $typeLabel . ')', '')
                ->trim(),
            'url' => route('events.show', $e),
            'pending' => $e->status instanceof \App\States\EventStatus\Pending,
            'cancelled' => $e->status instanceof \App\States\EventStatus\Cancelled,
            'is_remote' => in_array($formatValue, ['online', 'hybrid'], true),
        ];
    })->values())->toArray();
@endphp

<div class="min-h-screen bg-slate-50/80" x-data='{
        shareModalOpen: false,
        qrModalOpen: false,
        qrActiveUrl: null,
        qrActiveLabel: null,
        openQr(url, label) {
            this.qrActiveUrl = url;
            this.qrActiveLabel = label;
            this.qrModalOpen = true;
        },
        copied: false,
        shareData: @json($shareData),
        copyPrompt: @json(__('Copy this link:')),
        trackEndpoint: @json(route('dawah-share.track')),
        providerQueryParameter: @json(config('dawah-share.provider_query_parameter', 'channel')),
        attributedShareData: null,
        async resolveShareData() {
            if (this.attributedShareData) {
                return this.attributedShareData;
            }

            const params = new URLSearchParams({
                url: this.shareData.sourceUrl,
                text: this.shareData.shareText,
                title: this.shareData.fallbackTitle,
            });
            const response = await fetch(`${this.shareData.payloadEndpoint}?${params.toString()}`, {
                headers: {
                    Accept: "application/json",
                },
            });

            if (!response.ok) {
                return this.shareData;
            }

            const payload = await response.json();
            this.attributedShareData = {
                ...this.shareData,
                url: payload.url,
                tracking_token: payload.tracking_token ?? null,
            };

            return this.attributedShareData;
        },
        async sharePayloadForChannel(provider = null) {
            const shareData = await this.resolveShareData();

            if (! shareData || ! provider || ! shareData.tracking_token) {
                return shareData;
            }

            try {
                const shareUrl = new URL(shareData.url, window.location.origin);
                shareUrl.searchParams.set(this.providerQueryParameter, provider);

                return {
                    ...shareData,
                    url: shareUrl.toString(),
                };
            } catch (error) {
                return shareData;
            }
        },
        async trackShare(provider) {
            const shareData = await this.resolveShareData();

            if (! shareData?.tracking_token) {
                return;
            }

            const csrfToken = document.querySelector("meta[name=csrf-token]")?.content;

            if (! csrfToken) {
                return;
            }

            await fetch(this.trackEndpoint, {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": csrfToken,
                },
                body: JSON.stringify({
                    provider,
                    tracking_token: shareData.tracking_token,
                }),
            });
        },
        async nativeShare() {
            const shareData = await this.sharePayloadForChannel("native_share");
            if (navigator.share) {
                try {
                    await navigator.share(shareData);
                    await this.trackShare("native_share");
                } catch (error) {
                }

                return;
            }

            await this.copyLink();
        },
        async copyLink(shouldTrack = true, provider = "copy_link") {
            const shareData = await this.sharePayloadForChannel(provider);
            if (navigator.clipboard) {
                navigator.clipboard.writeText(shareData.url).then(async () => {
                    if (shouldTrack) {
                        await this.trackShare(provider);
                    }

                    this.copied = true;
                    setTimeout(() => { this.copied = false; }, 2000);
                }, async () => {
                    window.prompt(this.copyPrompt, shareData.url);

                    if (shouldTrack) {
                        await this.trackShare(provider);
                    }
                });
                return;
            }

            window.prompt(this.copyPrompt, shareData.url);

            if (shouldTrack) {
                await this.trackShare(provider);
            }
        },
        openShareModal() {
            this.shareModalOpen = true;
            this.copied = false;
        },
     }'>

    {{-- ═══════════════════════════════════════════════════════════
    CINEMATIC HERO — Dramatic, atmospheric header
    ═══════════════════════════════════════════════════════════ --}}
    <header
        class="noise-overlay relative isolate overflow-hidden bg-gradient-to-br from-slate-950 via-emerald-950/80 to-slate-950"
        style="min-height: 320px">
        {{-- Ambient gradient background orbs — animated floating --}}
        <div class="pointer-events-none absolute inset-0">
            <div
                class="animate-float-drift absolute -top-24 left-[15%] h-[28rem] w-[28rem] rounded-full bg-emerald-500/25 blur-[100px]">
            </div>
            <div
                class="animate-float-drift-alt absolute -bottom-20 right-[10%] h-[22rem] w-[22rem] rounded-full bg-gold-400/18 blur-[90px]">
            </div>
            <div
                class="animate-float-drift-slow absolute top-10 right-[40%] h-[18rem] w-[18rem] rounded-full bg-teal-400/18 blur-[80px]">
            </div>
            {{-- Extra accent orbs for richer depth --}}
            <div
                class="animate-float-drift-alt absolute -top-10 right-[20%] h-[16rem] w-[16rem] rounded-full bg-emerald-400/12 blur-[70px]">
            </div>
            <div
                class="animate-float-drift absolute -bottom-8 left-[25%] h-[18rem] w-[18rem] rounded-full bg-gold-300/10 blur-[100px]">
            </div>
        </div>

        {{-- Spotlight radial glow behind content --}}
        <div
            class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_80%_70%_at_55%_50%,rgba(16,185,129,0.14)_0%,transparent_60%)]">
        </div>

        {{-- Cover image --}}
        @if($coverUrl)
            <img src="{{ $coverUrl }}" alt=""
                class="absolute inset-0 h-full w-full object-cover opacity-30 mix-blend-luminosity" loading="eager">
        @endif
        {{-- Islamic geometric pattern overlay --}}
        <div class="absolute inset-0 opacity-[0.03]"
            style="background-image: url('{{ asset('images/pattern-bg.png') }}'); background-size: 200px;"></div>
        {{-- Mesh gradient layer for depth --}}
        <div
            class="absolute inset-0 bg-[conic-gradient(from_140deg_at_75%_30%,transparent_35%,rgba(16,185,129,0.08)_50%,transparent_65%)]">
        </div>
        {{-- Bottom gradient fade — lighter to let colors breathe --}}
        <div class="absolute inset-0 bg-gradient-to-t from-slate-950/90 via-transparent to-transparent"></div>
        {{-- Side vignette for depth --}}
        <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_center,transparent_60%,rgba(0,0,0,0.3)_100%)]">
        </div>
        {{-- Top inner shadow for containment --}}
        <div class="absolute inset-x-0 top-0 h-24 bg-gradient-to-b from-slate-950/60 to-transparent"></div>

        {{-- Hero content: Logo + Name --}}
        <div
            class="container relative z-10 mx-auto flex max-w-6xl flex-col items-center gap-6 px-6 pb-10 pt-12 sm:flex-row sm:items-center sm:gap-8 lg:px-8 lg:pb-12 lg:pt-16">
            {{-- Logo with breathing glow --}}
            <div class="animate-scale-in relative shrink-0" style="animation-delay: 200ms; opacity: 0;">
                <div
                    class="animate-glow-breathe absolute -inset-2.5 rounded-[1.5rem] bg-gradient-to-br from-emerald-400/30 via-gold-400/15 to-emerald-600/30 blur-lg">
                </div>
                <div
                    class="absolute -inset-1.5 rounded-[1.25rem] bg-gradient-to-br from-emerald-400/20 via-transparent to-gold-400/20">
                </div>
                <div
                    class="relative aspect-video w-44 overflow-hidden rounded-2xl border-2 border-white/20 bg-slate-800 shadow-2xl shadow-emerald-950/50 ring-1 ring-white/15 sm:w-56">
                    @if($heroInstitutionImageUrl)
                        <img src="{{ $heroInstitutionImageUrl }}" alt="{{ $institution->name }}"
                            class="h-full w-full object-cover" width="224" height="126">
                    @else
                        <div
                            class="flex h-full w-full items-center justify-center bg-gradient-to-br from-emerald-800 to-emerald-950 relative">
                            <div class="absolute inset-0 opacity-10"
                                style="background-image: url('{{ asset('images/pattern-bg.png') }}'); background-size: 80px;">
                            </div>
                            @php
                                $initials = collect(explode(' ', $institution->name))
                                    ->filter(fn($w) => !in_array(mb_strtolower($w), ['al', 'bin', 'b.', 'masjid', 'surau', 'pusat', 'dan', 'dan']))
                                    ->map(fn($word) => mb_substr($word, 0, 1))
                                    ->take(2)
                                    ->implode('');
                                if (empty($initials)) {
                                    $initials = mb_substr($institution->name, 0, 1);
                                }
                            @endphp
                            <span
                                class="relative font-heading text-4xl font-black text-white/80 tracking-tighter select-none sm:text-5xl">{{ $initials }}</span>
                        </div>
                    @endif
                </div>
                {{-- Institution type badge --}}
                @if($typeLabel)
                    <span
                        class="absolute -bottom-1.5 -right-1.5 flex items-center justify-center rounded-xl border-2 border-slate-950 bg-gradient-to-br from-emerald-500 to-emerald-700 px-2.5 py-1 text-[10px] font-bold text-white shadow-lg">
                        {{ $typeLabel }}
                    </span>
                @endif
            </div>

            {{-- Name & meta --}}
            <div class="animate-fade-in-up flex-1 text-center sm:text-left" style="animation-delay: 350ms; opacity: 0;">
                <h1
                    class="text-hero-glow font-heading text-3xl font-bold tracking-tight text-white sm:text-4xl lg:text-5xl">
                    {{ $institution->name }}
                </h1>
                {{-- Decorative gold shimmer line --}}
                <div
                    class="shimmer-line mx-auto mt-3 h-0.5 w-20 rounded-full bg-gradient-to-r from-gold-400/80 via-gold-300/60 to-gold-600/30 sm:mx-0">
                </div>

                {{-- Quick stats --}}
                <div class="mt-4 flex flex-wrap items-center justify-center gap-2.5 sm:justify-start">
                    @if($languages->isNotEmpty())
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-medium text-slate-300 backdrop-blur-sm">
                            <svg class="h-3 w-3 text-slate-400/70" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M10.5 21l5.25-11.25L21 21m-9-3h7.5M3 5.621a48.474 48.474 0 016-.371m0 0c1.12 0 2.233.038 3.334.114M9 5.25V3m3.334 2.364C11.176 10.658 7.69 15.08 3 17.502m9.334-12.138c.896.061 1.785.147 2.666.257m-4.589 8.495a18.023 18.023 0 01-3.827-5.802" />
                            </svg>
                            {{ $languages->pluck('name')->implode(', ') }}
                        </span>
                    @endif
                </div>

                {{-- Follow button --}}
                <div class="mt-4 flex flex-wrap items-center justify-center gap-2.5 sm:justify-start">
                    @if($locationString)
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-medium text-slate-300 backdrop-blur-sm">
                            <svg class="h-3 w-3 text-emerald-400/70" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                            </svg>
                            {{ $locationString }}
                        </span>
                    @endif

                    <button wire:click="toggleFollow" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 text-xs font-semibold transition-all duration-200 {{ $this->isFollowing ? 'border border-emerald-400/40 bg-emerald-500/20 text-emerald-300 hover:border-red-400/40 hover:bg-red-500/20 hover:text-red-300' : 'border border-white/15 bg-white/10 text-white hover:border-emerald-400/40 hover:bg-emerald-500/20 hover:text-emerald-300' }} backdrop-blur-sm"
                        x-data="{ hovering: false }" @mouseenter="hovering = true" @mouseleave="hovering = false">
                        @if($this->isFollowing)
                            <template x-if="!hovering">
                                <span class="inline-flex items-center gap-1.5">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                        stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                    {{ __('Mengikuti') }}
                                </span>
                            </template>
                            <template x-if="hovering">
                                <span class="inline-flex items-center gap-1.5">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                        stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                    {{ __('Nyahikut') }}
                                </span>
                            </template>
                        @else
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            {{ __('Ikuti') }}
                        @endif
                    </button>

                    <button type="button" @click="openShareModal()"
                        class="inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-white/10 px-4 py-1.5 text-xs font-semibold text-white transition-all duration-200 hover:border-emerald-400/40 hover:bg-emerald-500/20 hover:text-emerald-300 backdrop-blur-sm">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
                        </svg>
                        {{ __('Kongsi') }}
                    </button>

                    @can('update', $institution)
                        <a href="{{ route('filament.admin.resources.institutions.edit', ['record' => $institution]) }}"
                            target="_blank" rel="noopener noreferrer"
                            class="inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-white/10 px-4 py-1.5 text-xs font-semibold text-white transition-all duration-200 hover:border-gold-400/40 hover:bg-gold-500/20 hover:text-gold-200 backdrop-blur-sm">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M16.862 3.487a2.25 2.25 0 113.182 3.182L7.5 19.213l-4.5.9.9-4.5L16.862 3.487z" />
                            </svg>
                            {{ __('Edit Institusi') }}
                        </a>
                    @endcan
                </div>

            </div>
        </div>
        {{-- Layered bottom edge --}}
        <div
            class="absolute inset-x-0 bottom-0 h-px bg-gradient-to-r from-transparent via-emerald-500/40 to-transparent">
        </div>
        <div class="absolute inset-x-0 bottom-px h-px bg-gradient-to-r from-transparent via-gold-400/20 to-transparent">
        </div>
        <div class="absolute inset-x-0 bottom-0 h-8 bg-gradient-to-t from-slate-50/80 to-transparent"></div>
    </header>

    {{-- ═══════════════════════════════════════════════════════════
    MAIN CONTENT
    ═══════════════════════════════════════════════════════════ --}}
    {{-- Gradient continuation from hero --}}
    <div class="pointer-events-none relative -mt-1">
        <div
            class="absolute inset-x-0 top-0 h-40 bg-gradient-to-b from-emerald-950/[0.06] via-emerald-50/30 to-transparent">
        </div>
    </div>
    <div class="container relative mx-auto mt-4 max-w-6xl px-6 pb-16 lg:px-8">
        <div class="flex flex-col gap-8 lg:grid lg:grid-cols-[1fr_340px]">

            {{-- LEFT COLUMN — Main Content --}}
            <div class="institution-main-column order-2 space-y-10 lg:order-1">

                {{-- ─── EVENTS (Upcoming / Past) ─── --}}
                <section class="scroll-reveal reveal-up revealed" x-intersect.once="$el.classList.add('revealed')"
                    x-data="{ tab: 'upcoming', view: 'list', calendarMonth: new Date().getMonth(), calendarYear: new Date().getFullYear(), calendarEvents: {{ Js::from($calendarEvents) }} }">
                    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white shadow-lg shadow-emerald-500/25">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                    stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                                </svg>
                            </div>
                            <div>
                                <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('Majlis') }}</h2>
                                <div
                                    class="mt-0.5 h-0.5 w-12 rounded-full bg-gradient-to-r from-emerald-500 to-transparent">
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            {{-- Tab toggle: Upcoming / Past --}}
                            <div class="flex items-center gap-1 rounded-xl bg-slate-100 p-1">
                                <button @click="tab = 'upcoming'; view = 'list'"
                                    :class="tab === 'upcoming' ? 'bg-white shadow-sm text-slate-900' : 'text-slate-500 hover:text-slate-700'"
                                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-all duration-200">
                                    <svg class="hidden h-3.5 w-3.5 sm:block" fill="none" viewBox="0 0 24 24"
                                        stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                                    </svg>
                                    {{ __('Akan Datang') }}
                                    <span
                                        class="ml-0.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-slate-200 px-1.5 text-[10px] font-bold text-slate-600">{{ $upcomingTotal }}</span>
                                </button>
                                <button @click="tab = 'past'"
                                    :class="tab === 'past' ? 'bg-white shadow-sm text-slate-900' : 'text-slate-500 hover:text-slate-700'"
                                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-all duration-200 disabled:cursor-not-allowed disabled:opacity-50"
                                    @disabled($pastTotal === 0)>
                                    <svg class="hidden h-3.5 w-3.5 sm:block" fill="none" viewBox="0 0 24 24"
                                        stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    {{ __('Lepas') }}
                                    <span
                                        class="ml-0.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-slate-200 px-1.5 text-[10px] font-bold text-slate-600">{{ $pastTotal }}</span>
                                </button>
                            </div>

                            {{-- View toggle (list/calendar) — only for upcoming tab --}}
                            @if($upcomingEvents->isNotEmpty())
                                <div x-show="tab === 'upcoming'"
                                    class="flex items-center gap-1 rounded-xl bg-slate-100 p-1">
                                    <button @click="view = 'list'"
                                        :class="view === 'list' ? 'bg-white shadow-sm text-slate-900' : 'text-slate-500 hover:text-slate-700'"
                                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-all duration-200">
                                        <svg class="hidden h-3.5 w-3.5 sm:block" fill="none" viewBox="0 0 24 24"
                                            stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                                        </svg>
                                        {{ __('Senarai') }}
                                    </button>
                                    <button @click="view = 'calendar'"
                                        :class="view === 'calendar' ? 'bg-white shadow-sm text-slate-900' : 'text-slate-500 hover:text-slate-700'"
                                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-all duration-200">
                                        <svg class="hidden h-3.5 w-3.5 sm:block" fill="none" viewBox="0 0 24 24"
                                            stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-9-6h.008v.008H12v-.008zM12 15h.008v.008H12V15zm0 2.25h.008v.008H12v-.008zM9.75 15h.008v.008H9.75V15zm0 2.25h.008v.008H9.75v-.008zM7.5 15h.008v.008H7.5V15zm0 2.25h.008v.008H7.5v-.008zm6.75-4.5h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V15zm0 2.25h.008v.008h-.008v-.008zm2.25-4.5h.008v.008H16.5v-.008zm0 2.25h.008v.008H16.5V15z" />
                                        </svg>
                                        {{ __('Kalendar') }}
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>

                    @if($showPendingEventStatusNotice || $showCancelledEventStatusNotice)
                        <x-public.moderation-status-note
                            :show-pending="$showPendingEventStatusNotice"
                            :show-cancelled="$showCancelledEventStatusNotice"
                            class="mb-6"
                        />
                    @endif

                    {{-- ═══ UPCOMING TAB ═══ --}}
                    <div x-show="tab === 'upcoming'" x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 translate-y-2"
                        x-transition:enter-end="opacity-100 translate-y-0">
                        @if($upcomingEvents->isEmpty())
                            <div class="rounded-2xl border-2 border-dashed border-slate-200 bg-white/60 p-12 text-center">
                                <div
                                    class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-50">
                                    <svg class="h-8 w-8 text-emerald-300" fill="none" viewBox="0 0 24 24"
                                        stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                                    </svg>
                                </div>
                                <p class="text-base font-semibold text-slate-500">
                                    {{ __('Tiada majlis dijadualkan buat masa ini') }}
                                </p>
                                <p class="mt-1 text-sm text-slate-400">
                                    {{ __('Semak semula nanti untuk kemas kini terbaru.') }}
                                </p>
                            </div>
                        @else
                            {{-- LIST VIEW --}}
                            <div x-show="view === 'list'" x-transition:enter="transition ease-out duration-200"
                                x-transition:enter-start="opacity-0 translate-y-2"
                                x-transition:enter-end="opacity-100 translate-y-0">
                                <div class="space-y-4">
                                    @foreach($upcomingEvents as $event)
                                        @php
                                            $venueLocation = $resolveVenueLocation($event);
                                            $eventPeople = $resolveEventPeople($event);
                                            $speakerAvatarStack = $resolveEventSpeakerAvatarStack($event);
                                            $bookReferenceTitle = $event->reference_study_subtitle;
                                            $eventFormatValue = $event->event_format?->value ?? $event->event_format;
                                            $isRemoteEvent = in_array($eventFormatValue, ['online', 'hybrid'], true);
                                            $isPendingEvent = $event->status instanceof \App\States\EventStatus\Pending;
                                            $isCancelledEvent = $event->status instanceof \App\States\EventStatus\Cancelled;
                                        @endphp
                                        <a href="{{ route('events.show', $event) }}" wire:navigate
                                            wire:key="upcoming-{{ $event->id }}"
                                            class="group relative flex overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm ring-1 ring-transparent transition-all duration-300 hover:-translate-y-0.5 hover:border-emerald-200/80 hover:ring-emerald-100 hover:shadow-xl hover:shadow-emerald-500/[0.08]">
                                            {{-- Date accent sidebar --}}
                                            <div
                                                class="flex w-[4.5rem] shrink-0 flex-col items-center justify-center bg-gradient-to-b {{ $isCancelledEvent ? 'from-rose-600 to-rose-800' : ($isPendingEvent ? 'from-amber-600 to-amber-800' : ($isRemoteEvent ? 'from-sky-600 to-sky-800' : 'from-emerald-600 to-emerald-800')) }} p-2.5 text-white sm:w-24 sm:p-3">
                                                <span
                                                    class="text-[10px] font-bold uppercase tracking-widest {{ $isCancelledEvent ? 'text-rose-200/80' : ($isPendingEvent ? 'text-amber-200/80' : ($isRemoteEvent ? 'text-sky-200/80' : 'text-emerald-200/80')) }} sm:text-[11px]">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'l') }}</span>
                                                <span
                                                    class="font-heading text-2xl font-black leading-none sm:text-4xl">{{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'd') }}</span>
                                                <span
                                                    class="mt-0.5 text-[11px] font-bold tracking-wide {{ $isCancelledEvent ? 'text-rose-200/80' : ($isPendingEvent ? 'text-amber-200/80' : ($isRemoteEvent ? 'text-sky-200/80' : 'text-emerald-200/80')) }} sm:text-[13px]">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'F') }}</span>
                                            </div>
                                            {{-- Event details --}}
                                            <div class="flex flex-1 flex-col justify-center gap-2 p-4 sm:p-5">
                                                <div class="flex items-start justify-between gap-3">
                                                    <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                                                        <span
                                                            class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-200/60">
                                                            {{ $resolveEventTypeLabel($event->event_type) }}
                                                        </span>
                                                        @if($event->status instanceof \App\States\EventStatus\Pending)
                                                            <span
                                                                class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-[11px] font-semibold text-amber-700 ring-1 ring-amber-200/60">
                                                                {{ __('Menunggu Kelulusan') }}
                                                            </span>
                                                        @endif
                                                        @if($event->status instanceof \App\States\EventStatus\Cancelled)
                                                            <span
                                                                class="inline-flex items-center rounded-full bg-rose-50 px-2.5 py-0.5 text-[11px] font-semibold text-rose-700 ring-1 ring-rose-200/60">
                                                                {{ __('Dibatalkan') }}
                                                            </span>
                                                        @endif
                                                        @if($isRemoteEvent)
                                                            <span
                                                                class="inline-flex animate-pulse items-center gap-1 rounded-full bg-sky-50 px-2.5 py-0.5 text-[11px] font-semibold text-sky-700 ring-1 ring-sky-200/80">
                                                                <span class="h-1.5 w-1.5 rounded-full bg-sky-500"></span>
                                                                {{ $eventFormatValue === 'hybrid' ? __('Hybrid') : __('Online') }}
                                                            </span>
                                                        @endif
                                                    </div>
                                                    @if($speakerAvatarStack['items']->isNotEmpty())
                                                        <div class="shrink-0">
                                                            <div class="flex -space-x-3" aria-label="{{ __('Penceramah') }}">
                                                                @foreach($speakerAvatarStack['items'] as $avatar)
                                                                    <img src="{{ $avatar['url'] }}" alt="{{ $avatar['name'] }}"
                                                                        title="{{ $avatar['name'] }}"
                                                                        class="h-9 w-9 rounded-full object-cover ring-2 ring-white shadow-sm sm:h-11 sm:w-11">
                                                                @endforeach
                                                                @if($speakerAvatarStack['overflow'] > 0)
                                                                    <span
                                                                        class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-[10px] font-semibold text-slate-600 ring-2 ring-white shadow-sm sm:h-11 sm:w-11 sm:text-[11px]">+{{ $speakerAvatarStack['overflow'] }}</span>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    @endif
                                                </div>
                                                <h3
                                                    class="font-heading text-base font-bold leading-snug text-slate-900 transition-colors group-hover:text-emerald-700 sm:text-lg">
                                                    {{ $event->title }}
                                                </h3>
                                                @if($bookReferenceTitle)
                                                    <p class="pl-3 text-sm font-bold italic text-slate-500 sm:pl-4">
                                                        {{ $bookReferenceTitle }}
                                                    </p>
                                                @endif
                                                <div class="space-y-1 text-sm text-slate-500">
                                                    <div class="flex items-center gap-1.5">
                                                        <svg class="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24"
                                                            stroke="currentColor" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                        </svg>
                                                        {{ $resolveEventTimeDisplay($event) }}
                                                        @if($event->ends_at)
                                                            <span class="text-slate-300">–</span>
                                                            {{ $resolveEventEndTimeDisplay($event) }}
                                                        @endif
                                                    </div>
                                                    @if($eventPeople['speakers'] !== '')
                                                        <div class="flex items-start gap-1.5">
                                                            <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" fill="none"
                                                                viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                                    d="M18 18.72a9.094 9.094 0 003.742-.479 3 3 0 00-4.682-2.72m.94 3.198v.75c0 .414-.336.75-.75.75H4.75a.75.75 0 01-.75-.75v-.75a4.5 4.5 0 014.5-4.5h4.5a4.5 4.5 0 014.5 4.5z" />
                                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                                    d="M15 7.5a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                                                            </svg>
                                                            <span class="line-clamp-2">{{ $eventPeople['speakers'] }}</span>
                                                        </div>
                                                    @endif
                                                    @if($eventPeople['roles'] !== '')
                                                        <div class="flex items-start gap-1.5">
                                                            <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" fill="none"
                                                                viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                                    d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.008v.008H3.75V6.75zm0 5.25h.008v.008H3.75V12zm0 5.25h.008v.008H3.75v-.008z" />
                                                            </svg>
                                                            <span class="line-clamp-2">{{ $eventPeople['roles'] }}</span>
                                                        </div>
                                                    @endif
                                                    @if($venueLocation && $eventFormatValue !== 'online')
                                                        <div class="flex items-center gap-1.5">
                                                            <svg class="h-3.5 w-3.5 shrink-0 text-slate-400" fill="none"
                                                                viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                                    d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                                d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                                                            </svg>
                                                            <span class="line-clamp-1">{{ $venueLocation }}</span>
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                            {{-- Arrow --}}
                                            <div class="hidden items-center pr-5 sm:flex">
                                                <div
                                                    class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-slate-400 transition-all duration-300 group-hover:bg-emerald-100 group-hover:text-emerald-600">
                                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                                        stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                                    </svg>
                                                </div>
                                            </div>
                                        </a>
                                    @endforeach
                                </div>

                                {{-- Load more --}}
                                @if($upcomingTotal > $upcomingEvents->count())
                                    <div class="mt-6 text-center">
                                        <button wire:click="loadMoreUpcoming" wire:loading.attr="disabled"
                                            class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-600 shadow-sm transition-all duration-200 hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-700 disabled:opacity-50">
                                            <span wire:loading.remove wire:target="loadMoreUpcoming">{{ __('Lihat Lagi') }}
                                                ({{ $upcomingTotal - $upcomingEvents->count() }} {{ __('lagi') }})</span>
                                            <span wire:loading wire:target="loadMoreUpcoming"
                                                class="inline-flex items-center gap-2">
                                                <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                        stroke-width="4" />
                                                    <path class="opacity-75" fill="currentColor"
                                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                                </svg>
                                                {{ __('Memuatkan...') }}
                                            </span>
                                        </button>
                                    </div>
                                @endif
                            </div>

                            {{-- CALENDAR VIEW --}}
                            <div x-show="view === 'calendar'" x-cloak x-transition:enter="transition ease-out duration-200"
                                x-transition:enter-start="opacity-0 translate-y-2"
                                x-transition:enter-end="opacity-100 translate-y-0">
                                <div class="overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm">
                                    {{-- Calendar header --}}
                                    <div
                                        class="flex items-center justify-between border-b border-slate-100 bg-gradient-to-r from-slate-50 to-transparent px-5 py-3">
                                        <button
                                            @click="calendarMonth--; if(calendarMonth < 0) { calendarMonth = 11; calendarYear--; }"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                                stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M15.75 19.5L8.25 12l7.5-7.5" />
                                            </svg>
                                        </button>
                                        <h3 class="text-sm font-bold text-slate-700"
                                            x-text="new Date(calendarYear, calendarMonth).toLocaleDateString('{{ app()->getLocale() }}', { month: 'long', year: 'numeric' })">
                                        </h3>
                                        <button
                                            @click="calendarMonth++; if(calendarMonth > 11) { calendarMonth = 0; calendarYear++; }"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                                stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                            </svg>
                                        </button>
                                    </div>
                                    {{-- Day headers --}}
                                    <div class="grid grid-cols-7 border-b border-slate-100 bg-slate-50/50">
                                        <template
                                            x-for="day in ['{{ __('Isn') }}','{{ __('Sel') }}','{{ __('Rab') }}','{{ __('Kha') }}','{{ __('Jum') }}','{{ __('Sab') }}','{{ __('Ahd') }}']">
                                            <div class="py-2 text-center text-[10px] font-bold uppercase tracking-widest text-slate-400"
                                                x-text="day"></div>
                                        </template>
                                    </div>
                                    {{-- Calendar grid --}}
                                    <div class="grid grid-cols-7">
                                        <template x-for="(cell, idx) in (() => {
                                                            const first = new Date(calendarYear, calendarMonth, 1);
                                                            const lastDay = new Date(calendarYear, calendarMonth + 1, 0).getDate();
                                                            let startDay = first.getDay();
                                                            startDay = startDay === 0 ? 6 : startDay - 1;
                                                            const cells = [];
                                                            for (let i = 0; i < startDay; i++) cells.push({ day: null });
                                                            for (let d = 1; d <= lastDay; d++) {
                                                                const key = calendarYear + '-' + String(calendarMonth + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
                                                                cells.push({ day: d, key, events: calendarEvents[key] || [] });
                                                            }
                                                            return cells;
                                                        })()" :key="idx">
                                            <div class="relative min-h-[6rem] border-b border-r border-slate-100 p-1 sm:min-h-[7.5rem] sm:p-1.5"
                                                :class="cell.day === null ? 'bg-slate-50/30' : ''">
                                                <template x-if="cell.day !== null">
                                                    <div>
                                                        <span class="text-xs font-medium"
                                                            :class="cell.events?.length > 0 ? (cell.events.some(ev => ev.cancelled) ? 'font-bold text-rose-700' : (cell.events.some(ev => ev.pending) ? 'font-bold text-amber-700' : (cell.events.some(ev => ev.is_remote) ? 'font-bold text-sky-700' : 'font-bold text-emerald-700'))) : 'text-slate-400'"
                                                            x-text="cell.day"></span>
                                                        <template x-if="cell.events?.length > 0">
                                                            <div class="mt-0.5 space-y-0.5">
                                                                <template x-for="ev in cell.events.slice(0, 2)"
                                                                    :key="ev.id">
                                                                    <a :href="ev.url"
                                                                        class="block rounded-md border px-1.5 py-1 text-[10px] font-semibold leading-snug whitespace-normal break-words shadow-sm transition"
                                                                        :class="ev.cancelled ? 'border-rose-300 bg-rose-100 text-rose-900 shadow-rose-200/80 hover:bg-rose-200' : (ev.pending ? 'border-amber-300 bg-amber-100 text-amber-900 shadow-amber-200/80 hover:bg-amber-200' : (ev.is_remote ? 'border-sky-300 bg-sky-100 text-sky-900 shadow-sky-200/80 hover:bg-sky-200' : 'border-emerald-300 bg-emerald-100 text-emerald-900 shadow-emerald-200/80 hover:bg-emerald-200'))"
                                                                        x-text="ev.title"></a>
                                                                </template>
                                                                <template x-if="cell.events?.length > 2">
                                                                    <span class="block text-[9px] font-semibold"
                                                                        :class="cell.events.some(ev => ev.cancelled) ? 'text-rose-500' : (cell.events.some(ev => ev.pending) ? 'text-amber-500' : (cell.events.some(ev => ev.is_remote) ? 'text-sky-500' : 'text-emerald-500'))"
                                                                        x-text="'+' + (cell.events.length - 2) + ' ' + @js(__('lagi'))"></span>
                                                                </template>
                                                            </div>
                                                        </template>
                                                        <template x-if="cell.events?.length > 0">
                                                            <div
                                                                class="absolute bottom-1 left-1/2 flex -translate-x-1/2 gap-0.5 sm:hidden">
                                                                <template x-for="i in Math.min(cell.events.length, 3)"
                                                                    :key="i">
                                                                    <span class="h-1 w-1 rounded-full"
                                                                        :class="cell.events.some(ev => ev.cancelled) ? 'bg-rose-500' : (cell.events.some(ev => ev.pending) ? 'bg-amber-500' : (cell.events.some(ev => ev.is_remote) ? 'bg-sky-500' : 'bg-emerald-500'))"></span>
                                                                </template>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>{{-- end upcoming tab --}}

                    {{-- ═══ PAST EVENTS TAB ═══ --}}
                    @if($pastEvents->isNotEmpty())
                        <div x-show="tab === 'past'" x-cloak x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 translate-y-2"
                            x-transition:enter-end="opacity-100 translate-y-0">
                            <div class="space-y-4">
                                @foreach($pastEvents as $event)
                                    @php
                                        $pastVenueLocation = $resolveVenueLocation($event);
                                        $eventPeople = $resolveEventPeople($event);
                                        $speakerAvatarStack = $resolveEventSpeakerAvatarStack($event);
                                        $bookReferenceTitle = $event->reference_study_subtitle;
                                        $eventFormatValue = $event->event_format?->value ?? $event->event_format;
                                        $isRemoteEvent = in_array($eventFormatValue, ['online', 'hybrid'], true);
                                        $isPendingEvent = $event->status instanceof \App\States\EventStatus\Pending;
                                        $isCancelledEvent = $event->status instanceof \App\States\EventStatus\Cancelled;
                                    @endphp
                                    <a href="{{ route('events.show', $event) }}" wire:navigate wire:key="past-{{ $event->id }}"
                                        class="group relative flex overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm ring-1 ring-transparent transition-all duration-300 hover:-translate-y-0.5 hover:border-slate-300 hover:ring-slate-200 hover:shadow-xl hover:shadow-slate-500/[0.06]">
                                        <div
                                            class="flex w-[4.5rem] shrink-0 flex-col items-center justify-center bg-gradient-to-b {{ $isCancelledEvent ? 'from-rose-600 to-rose-800' : ($isPendingEvent ? 'from-amber-600 to-amber-800' : ($isRemoteEvent ? 'from-sky-600 to-sky-800' : 'from-slate-500 to-slate-700')) }} p-2.5 text-white sm:w-24 sm:p-3">
                                            <span
                                                class="text-[10px] font-bold uppercase tracking-widest {{ $isCancelledEvent ? 'text-rose-200/80' : ($isPendingEvent ? 'text-amber-200/80' : ($isRemoteEvent ? 'text-sky-200/80' : 'text-slate-300/80')) }} sm:text-[11px]">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'l') }}</span>
                                            <span
                                                class="font-heading text-2xl font-black leading-none sm:text-4xl">{{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'd') }}</span>
                                            <span
                                                class="mt-0.5 text-[11px] font-bold tracking-wide {{ $isCancelledEvent ? 'text-rose-200/80' : ($isPendingEvent ? 'text-amber-200/80' : ($isRemoteEvent ? 'text-sky-200/80' : 'text-slate-300/80')) }} sm:text-[13px]">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'F') }}</span>
                                        </div>
                                        <div class="flex flex-1 flex-col justify-center gap-2 p-4 sm:p-5">
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                                                    <span
                                                        class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-600 ring-1 ring-slate-200/60">
                                                        {{ $resolveEventTypeLabel($event->event_type) }}
                                                    </span>
                                                    @if($event->status instanceof \App\States\EventStatus\Pending)
                                                        <span
                                                            class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-[11px] font-semibold text-amber-700 ring-1 ring-amber-200/60">
                                                            {{ __('Menunggu Kelulusan') }}
                                                        </span>
                                                    @endif
                                                    @if($event->status instanceof \App\States\EventStatus\Cancelled)
                                                        <span
                                                            class="inline-flex items-center rounded-full bg-rose-50 px-2.5 py-0.5 text-[11px] font-semibold text-rose-700 ring-1 ring-rose-200/60">
                                                            {{ __('Dibatalkan') }}
                                                        </span>
                                                    @endif
                                                    @if($isRemoteEvent)
                                                        <span
                                                            class="inline-flex animate-pulse items-center gap-1 rounded-full bg-sky-50 px-2.5 py-0.5 text-[11px] font-semibold text-sky-700 ring-1 ring-sky-200/80">
                                                            <span class="h-1.5 w-1.5 rounded-full bg-sky-500"></span>
                                                            {{ $eventFormatValue === 'hybrid' ? __('Hybrid') : __('Online') }}
                                                        </span>
                                                    @endif
                                                    @if(!$isCancelledEvent)
                                                        <span
                                                            class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-[11px] font-semibold text-amber-700 ring-1 ring-amber-200/60">
                                                            {{ __('Selesai') }}
                                                        </span>
                                                    @endif
                                                </div>
                                                @if($speakerAvatarStack['items']->isNotEmpty())
                                                    <div class="shrink-0">
                                                        <div class="flex -space-x-3" aria-label="{{ __('Penceramah') }}">
                                                            @foreach($speakerAvatarStack['items'] as $avatar)
                                                                <img src="{{ $avatar['url'] }}" alt="{{ $avatar['name'] }}"
                                                                    title="{{ $avatar['name'] }}"
                                                                    class="h-9 w-9 rounded-full object-cover ring-2 ring-white shadow-sm sm:h-11 sm:w-11">
                                                            @endforeach
                                                            @if($speakerAvatarStack['overflow'] > 0)
                                                                <span
                                                                    class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-[10px] font-semibold text-slate-600 ring-2 ring-white shadow-sm sm:h-11 sm:w-11 sm:text-[11px]">+{{ $speakerAvatarStack['overflow'] }}</span>
                                                            @endif
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>
                                            <h3
                                                class="font-heading text-base font-bold leading-snug text-slate-900 transition-colors group-hover:text-slate-700 sm:text-lg">
                                                {{ $event->title }}
                                            </h3>
                                            @if($bookReferenceTitle)
                                                <p class="pl-3 text-sm font-bold italic text-slate-500 sm:pl-4">
                                                    {{ $bookReferenceTitle }}
                                                </p>
                                            @endif
                                            <div class="space-y-1 text-sm text-slate-500">
                                                <div class="flex items-center gap-1.5">
                                                    <svg class="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24"
                                                        stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                    {{ $resolveEventTimeDisplay($event) }}
                                                    @if($event->ends_at)
                                                        <span class="text-slate-300">–</span>
                                                        {{ $resolveEventEndTimeDisplay($event) }}
                                                    @endif
                                                </div>
                                                @if($eventPeople['speakers'] !== '')
                                                    <div class="flex items-start gap-1.5">
                                                        <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" fill="none"
                                                            viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                d="M18 18.72a9.094 9.094 0 003.742-.479 3 3 0 00-4.682-2.72m.94 3.198v.75c0 .414-.336.75-.75.75H4.75a.75.75 0 01-.75-.75v-.75a4.5 4.5 0 014.5-4.5h4.5a4.5 4.5 0 014.5 4.5z" />
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                d="M15 7.5a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                                                        </svg>
                                                        <span class="line-clamp-2">{{ $eventPeople['speakers'] }}</span>
                                                    </div>
                                                @endif
                                                @if($eventPeople['roles'] !== '')
                                                    <div class="flex items-start gap-1.5">
                                                        <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" fill="none"
                                                            viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.008v.008H3.75V6.75zm0 5.25h.008v.008H3.75V12zm0 5.25h.008v.008H3.75v-.008z" />
                                                        </svg>
                                                        <span class="line-clamp-2">{{ $eventPeople['roles'] }}</span>
                                                    </div>
                                                @endif
                                                @if($pastVenueLocation && $eventFormatValue !== 'online')
                                                    <div class="flex items-center gap-1.5">
                                                        <svg class="h-3.5 w-3.5 shrink-0 text-slate-400" fill="none"
                                                            viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                                                        </svg>
                                                        <span class="line-clamp-1">{{ $pastVenueLocation }}</span>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                        <div class="hidden items-center pr-5 sm:flex">
                                            <div
                                                class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-slate-400 transition-all duration-300 group-hover:bg-slate-200 group-hover:text-slate-600">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                                    stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                                </svg>
                                            </div>
                                        </div>
                                    </a>
                                @endforeach
                            </div>

                            @if($pastTotal > $pastEvents->count())
                                <div class="mt-6 text-center">
                                    <button wire:click="loadMorePast" wire:loading.attr="disabled"
                                        class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-600 shadow-sm transition-all duration-200 hover:border-slate-300 hover:bg-slate-50 disabled:opacity-50">
                                        <span wire:loading.remove wire:target="loadMorePast">{{ __('Lihat Lagi') }}
                                            ({{ $pastTotal - $pastEvents->count() }} {{ __('lagi') }})</span>
                                        <span wire:loading wire:target="loadMorePast" class="inline-flex items-center gap-2">
                                            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                    stroke-width="4" />
                                                <path class="opacity-75" fill="currentColor"
                                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                            </svg>
                                            {{ __('Memuatkan...') }}
                                        </span>
                                    </button>
                                </div>
                            @endif
                        </div>
                    @endif
                </section>

                {{-- ─── SPEAKERS ─── --}}
                @if($speakers->isNotEmpty())
                    <section class="scroll-reveal reveal-up revealed" x-intersect.once="$el.classList.add('revealed')"
                        style="--reveal-d: 80ms">
                        <div class="mb-5 flex items-center gap-3">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-gold-500 to-gold-700 text-white shadow-lg shadow-gold-500/20">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                                </svg>
                            </div>
                            <div>
                                <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('Barisan Penceramah') }}
                                </h2>
                                <div class="mt-0.5 h-0.5 w-12 rounded-full bg-gradient-to-r from-gold-500 to-transparent">
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3">
                            @foreach($speakers as $speaker)
                                @php
                                    $speakerAvatarUrl = $speaker->hasMedia('avatar')
                                        ? $speaker->getFirstMediaUrl('avatar', 'profile')
                                        : ($speaker->default_avatar_url ?? '');
                                    $speakerPosition = $speaker->pivot->position;
                                    $isPrimarySpeaker = $speaker->pivot->is_primary;
                                @endphp
                                <a href="{{ route('speakers.show', $speaker) }}" wire:navigate
                                    wire:key="speaker-{{ $speaker->id }}"
                                    class="group relative flex flex-col items-center gap-3 rounded-2xl border border-slate-200/70 bg-white p-5 shadow-sm transition-all duration-300 hover:-translate-y-0.5 hover:border-gold-200 hover:shadow-lg hover:shadow-gold-500/[0.06]">
                                    @if($isPrimarySpeaker)
                                        <div class="absolute right-2 top-2 flex h-5 w-5 items-center justify-center rounded-full bg-gold-100 text-gold-600"
                                            title="{{ __('Penceramah Utama') }}">
                                            <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M12 2l2.4 7.4h7.6l-6 4.6 2.3 7-6.3-4.6L5.7 21l2.3-7L2 9.4h7.6z" />
                                            </svg>
                                        </div>
                                    @endif
                                    <div
                                        class="relative h-36 w-36 sm:h-28 sm:w-28 md:h-32 md:w-32 overflow-hidden rounded-full border-2 border-slate-100 bg-slate-100 transition-all duration-300 group-hover:border-gold-200 group-hover:shadow-md">
                                        @if($speakerAvatarUrl)
                                            <img src="{{ $speakerAvatarUrl }}" alt="{{ $speaker->name }}"
                                                class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-110"
                                                loading="lazy">
                                        @else
                                            <div
                                                class="flex h-full w-full items-center justify-center bg-gradient-to-br from-slate-100 to-slate-200">
                                                <svg class="h-14 w-14 sm:h-12 sm:w-12 md:h-14 md:w-14 text-slate-300" fill="none"
                                                    viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                                                </svg>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="min-w-0 text-center">
                                        <h3
                                            class="truncate text-sm font-bold text-slate-900 transition-colors group-hover:text-gold-700">
                                            {{ $speaker->name }}
                                        </h3>
                                        @if($speakerPosition)
                                            <p class="mt-0.5 truncate text-xs text-slate-500">{{ $speakerPosition }}</p>
                                        @endif
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif

                {{-- ─── ABOUT / DESCRIPTION ─── --}}
                @if($institution->description)
                    <section class="scroll-reveal reveal-scale revealed" x-intersect.once="$el.classList.add('revealed')"
                        style="--reveal-d: 120ms">
                        <div class="mb-5 flex items-center gap-3">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-700 text-white shadow-lg shadow-emerald-500/20">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6M4.5 9.75v10.5h15V9.75" />
                                </svg>
                            </div>
                            <div>
                                <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('Mengenai Kami') }}</h2>
                                <div
                                    class="mt-0.5 h-0.5 w-12 rounded-full bg-gradient-to-r from-emerald-500 to-transparent">
                                </div>
                            </div>
                        </div>
                        <div class="relative rounded-2xl border border-slate-200/70 bg-white p-6 shadow-sm md:p-8">
                            {{-- Left accent bar --}}
                            <div
                                class="absolute inset-y-4 left-0 w-1 rounded-full bg-gradient-to-b from-emerald-400 via-emerald-500/60 to-transparent">
                            </div>
                            <div
                                class="prose prose-slate prose-sm max-w-none pl-4 prose-headings:font-heading prose-headings:tracking-tight prose-p:leading-relaxed prose-a:text-emerald-600 prose-a:no-underline hover:prose-a:underline prose-strong:text-slate-800">
                                {!! $institution->description !!}
                            </div>
                        </div>
                    </section>
                @endif

                {{-- ─── GALLERY ─── --}}
                @if($gallery->count() > 0)
                    <section class="scroll-reveal reveal-blur revealed" x-intersect.once="$el.classList.add('revealed')"
                        style="--reveal-d: 160ms">
                        <div class="mb-5 flex items-center gap-3">
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-slate-600 to-slate-800 text-white shadow-lg shadow-slate-500/20">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5a2.25 2.25 0 002.25-2.25V6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v12a2.25 2.25 0 002.25 2.25zm15-14.25a1.125 1.125 0 11-2.25 0 1.125 1.125 0 012.25 0z" />
                                </svg>
                            </div>
                            <div>
                                <h2 class="font-heading text-2xl font-bold text-slate-900">{{ __('Galeri') }}</h2>
                                <div class="mt-0.5 h-0.5 w-12 rounded-full bg-gradient-to-r from-slate-400 to-transparent">
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            @foreach($gallery as $index => $image)
                                <div
                                    class="group relative aspect-[4/3] overflow-hidden rounded-xl bg-slate-100 ring-1 ring-slate-200/50 transition-all duration-300 hover:ring-2 hover:ring-emerald-300/50 hover:shadow-lg {{ $index === 0 && $gallery->count() >= 3 ? 'col-span-2 row-span-2 aspect-square sm:aspect-[4/3]' : '' }}">
                                    <img src="{{ $image->getAvailableUrl(['gallery_thumb']) }}" alt="{{ __('Galeri') }}"
                                        class="h-full w-full object-cover transition-all duration-700 group-hover:scale-110"
                                        loading="lazy">
                                    <div
                                        class="absolute inset-0 bg-gradient-to-t from-black/30 via-transparent to-transparent opacity-0 transition-opacity duration-500 group-hover:opacity-100">
                                    </div>
                                    <div
                                        class="absolute bottom-3 left-3 opacity-0 transition-all duration-500 group-hover:opacity-100">
                                        <span
                                            class="rounded-lg bg-black/40 px-2.5 py-1 text-[11px] font-medium text-white/90 backdrop-blur-sm">{{ $index + 1 }}/{{ $gallery->count() }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

            </div>

            {{-- RIGHT COLUMN — Sidebar --}}
            <div class="institution-sidebar-column order-1 space-y-6 lg:order-2 lg:sticky lg:top-6 lg:self-start">

                {{-- ─── ISLAMIC INSPIRATION ─── --}}
                <x-sidebar-inspiration />

                {{-- ─── SOCIAL MEDIA (Mobile: below inspiration) ─── --}}
                @if($socialLinks->isNotEmpty())
                    <section
                        class="scroll-reveal reveal-right revealed rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm ring-1 ring-slate-100/50"
                        x-intersect.once="$el.classList.add('revealed')">
                        <div class="mb-4 flex items-center gap-3">
                            <h3 class="flex items-center gap-2 text-sm font-bold text-slate-900">
                                <svg class="h-4 w-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                    stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
                                </svg>
                                {{ __('Media Sosial') }}
                            </h3>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            @foreach($socialLinks as $social)
                                @php
                                    $platform = strtolower((string) $social->platform);
                                    $linkClass = match ($platform) {
                                        'website' => 'hover:text-emerald-600',
                                        'facebook' => 'hover:text-blue-600',
                                        'instagram' => 'hover:text-pink-600',
                                        'youtube' => 'hover:text-red-600',
                                        'telegram' => 'hover:text-sky-600',
                                        'whatsapp' => 'hover:text-green-600',
                                        default => 'hover:text-slate-900',
                                    };
                                    $title = \App\Enums\SocialMediaPlatform::tryFrom($platform)?->getLabel() ?? ucfirst($platform);
                                @endphp
                                <a href="{{ $social->resolved_url }}" target="_blank" rel="noopener noreferrer"
                                    class="group inline-flex items-center justify-center p-1 text-slate-500 transition-all duration-200 hover:-translate-y-0.5 {{ $linkClass }}"
                                    title="{{ $title }}">
                                    <img src="{{ $social->icon_url }}" alt="{{ $title }}" class="h-8 w-8" loading="lazy">
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif

                {{-- ─── CONTACT & ADDRESS ─── --}}
                @if($contacts->isNotEmpty() || $hasPhysicalAddress)
                    <div class="scroll-reveal reveal-right revealed rounded-2xl border border-slate-200/70 bg-white shadow-sm"
                        x-intersect.once="$el.classList.add('revealed')">
                        <div class="border-b border-slate-100 px-5 py-4">
                            <h3 class="flex items-center gap-2 text-sm font-bold text-slate-900">
                                <svg class="h-4 w-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                    stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" />
                                </svg>
                                {{ __('Hubungi') }}
                            </h3>
                        </div>
                        <div class="space-y-4 p-5">
                            @foreach($contacts as $contact)
                                @if($contact->value)
                                    @php
                                        $categoryValue = $contact->category instanceof \App\Enums\ContactCategory
                                            ? $contact->category->value
                                            : strtolower((string) $contact->category);
                                        $contactValue = (string) $contact->value;
                                        $phoneValue = preg_replace('/[^0-9+]/', '', $contactValue);
                                        $contactHref = match ($categoryValue) {
                                            'email' => 'mailto:' . $contactValue,
                                            'phone' => filled($phoneValue) ? 'tel:' . $phoneValue : null,
                                            default => null,
                                        };
                                    @endphp

                                    @if($contactHref)
                                        <a href="{{ $contactHref }}"
                                            class="group flex items-center gap-3 transition-opacity hover:opacity-90"
                                            @if($categoryValue === 'email') aria-label="{{ __('Hantar emel kepada :value', ['value' => $contactValue]) }}" @else aria-label="{{ __('Hubungi :value', ['value' => $contactValue]) }}" @endif>
                                            <div
                                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 transition-colors group-hover:bg-emerald-100">
                                                @if($categoryValue === 'email')
                                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                                        stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                                                    </svg>
                                                @else
                                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                                        stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" />
                                                    </svg>
                                                @endif
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <span
                                                    class="block break-all text-sm font-medium text-slate-800">{{ $contactValue }}</span>
                                            </div>
                                        </a>
                                    @else
                                        <div class="flex items-center gap-3 group">
                                            <div
                                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 transition-colors group-hover:bg-emerald-100">
                                                @if($categoryValue === 'email')
                                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                                        stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                                                    </svg>
                                                @else
                                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                                        stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" />
                                                    </svg>
                                                @endif
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <span
                                                    class="block break-all text-sm font-medium text-slate-800">{{ $contactValue }}</span>
                                            </div>
                                        </div>
                                    @endif
                                @endif
                            @endforeach

                            @if($hasPhysicalAddress)
                                <div class="flex items-center gap-3 group">
                                    <div
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 transition-colors group-hover:bg-emerald-100">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                            stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                                        </svg>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium leading-snug text-slate-800">
                                            @if(filled($streetAddressLine))
                                                {{ $streetAddressLine }}
                                            @endif

                                            @if(filled($localityAddressLine))
                                                @if(filled($streetAddressLine)) <br> @endif
                                                {{ $localityAddressLine }}
                                            @endif

                                            @if(filled($regionalAddressLine))
                                                @if(filled($streetAddressLine) || filled($localityAddressLine)) <br> @endif
                                                {{ $regionalAddressLine }}
                                            @endif
                                        </p>
                                    </div>
                                </div>

                                {{-- Mini map preview --}}
                                @if($address->google_maps_url)
                                    @if($googleMapsEmbedUrl)
                                        <div class="overflow-hidden rounded-xl border border-slate-200/70">
                                            <iframe src="{{ $googleMapsEmbedUrl }}" class="h-[220px] w-full" loading="lazy"
                                                referrerpolicy="no-referrer-when-downgrade" allowfullscreen
                                                title="{{ __('Peta Lokasi') }}"></iframe>
                                        </div>
                                    @else
                                        <a href="{{ $address->google_maps_url }}" target="_blank" rel="noopener"
                                            class="flex items-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm font-semibold text-blue-700 transition-colors hover:bg-blue-100">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                                            </svg>
                                            {{ __('Buka di Google Maps') }}
                                        </a>
                                    @endif
                                @endif

                                {{-- Navigation buttons --}}
                                @if($wazeUrl || $address->google_maps_url)
                                    <div class="flex flex-wrap gap-2 pl-11">
                                        @if($wazeUrl)
                                            <a href="{{ $wazeUrl }}" target="_blank" rel="noopener"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-cyan-200 bg-cyan-50 px-3 py-1.5 text-xs font-bold text-cyan-700 transition-colors hover:bg-cyan-100">
                                                <img src="{{ asset('images/waze-app-icon-seeklogo.svg') }}" alt="Waze"
                                                    class="h-3.5 w-3.5" loading="lazy">
                                                Waze
                                            </a>
                                        @endif
                                        @if($address->google_maps_url)
                                            <a href="{{ $address->google_maps_url }}" target="_blank" rel="noopener"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700 transition-colors hover:bg-blue-100">
                                                <img src="{{ asset('images/google-maps.svg') }}" alt="Google Maps" class="h-3.5 w-3.5"
                                                    loading="lazy">
                                                Google Maps
                                            </a>
                                        @endif
                                    </div>
                                @endif
                            @endif
                        </div>
                    </div>
                @endif

                {{-- ─── SPACES / FACILITIES ─── --}}
                @if($spaces->isNotEmpty())
                    <div class="scroll-reveal reveal-right revealed rounded-2xl border border-slate-200/70 bg-white shadow-sm"
                        x-intersect.once="$el.classList.add('revealed')" style="--reveal-d: 100ms">
                        <div class="border-b border-slate-100 px-5 py-4">
                            <h3 class="flex items-center gap-2 text-sm font-bold text-slate-900">
                                <svg class="h-4 w-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                    stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                                </svg>
                                {{ __('Ruang & Kemudahan') }}
                            </h3>
                        </div>
                        <div class="divide-y divide-slate-100">
                            @foreach($spaces as $space)
                                <div class="flex items-center gap-3 px-5 py-3">
                                    <div
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                            stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M2.25 7.125C2.25 6.504 2.754 6 3.375 6h6c.621 0 1.125.504 1.125 1.125v3.75c0 .621-.504 1.125-1.125 1.125h-6a1.125 1.125 0 01-1.125-1.125v-3.75zM14.25 8.625c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v8.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 01-1.125-1.125v-8.25zM3.75 16.125c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v2.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 01-1.125-1.125v-2.25z" />
                                        </svg>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <span class="text-sm font-semibold text-slate-800">{{ $space->name }}</span>
                                        @if($space->capacity)
                                            <span class="ml-1.5 text-xs text-slate-400">({{ $space->capacity }}
                                                {{ __('orang') }})</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- ─── DONATION CHANNELS ─── --}}
                @if($donationChannels->isNotEmpty())
                    <div class="scroll-reveal reveal-right revealed rounded-2xl border border-gold-200/60 bg-gradient-to-b from-gold-50/50 to-white shadow-sm"
                        x-intersect.once="$el.classList.add('revealed')" style="--reveal-d: 180ms">
                        <div class="border-b border-gold-200/40 px-5 py-4">
                            <h3 class="flex items-center gap-2 text-sm font-bold text-slate-900">
                                <svg class="h-4 w-4 text-gold-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                    stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z" />
                                </svg>
                                {{ __('Saluran Derma') }}
                            </h3>
                        </div>
                        <div class="divide-y divide-gold-100/50 p-2">
                            @foreach($donationChannels as $channel)
                                <div class="rounded-xl p-4 transition-colors hover:bg-gold-50/50">
                                    <div class="flex items-start gap-4">
                                        @php
                                            $thumbUrl = $channel->getFirstMediaUrl('qr', 'thumb');
                                            $fullUrl = $channel->getFirstMediaUrl('qr');
                                            $displayLabel = $channel->label ?: $channel->recipient;
                                        @endphp
                                        @if($thumbUrl)
                                            <button type="button"
                                                @click="openQr('{{ $fullUrl }}', '{{ $displayLabel }}')"
                                                class="group relative shrink-0 transition-transform active:scale-95">
                                                <img src="{{ $thumbUrl }}" alt="{{ __('Kod QR') }}"
                                                    class="h-16 w-16 object-contain" loading="lazy">
                                                <div
                                                    class="absolute inset-0 flex items-center justify-center bg-gold-900/5 opacity-0 transition group-hover:opacity-100">
                                                    <svg class="h-5 w-5 text-gold-600" fill="none" viewBox="0 0 24 24"
                                                        stroke="currentColor" stroke-width="2.5">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607zM10.5 7.5v6m3-3h-6" />
                                                    </svg>
                                                </div>
                                            </button>
                                        @endif
                                        <div class="min-w-0 flex-1">
                                            @if($channel->label)
                                                <p class="text-xs font-bold text-gold-700">{{ $channel->label }}</p>
                                            @endif
                                            <p class="mt-0.5 text-sm font-semibold text-slate-900">{{ $channel->recipient }}</p>
                                            <p class="mt-0.5 text-xs text-slate-500">
                                                <span
                                                    class="inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-slate-600">{{ $channel->method_display }}</span>
                                            </p>
                                            <p class="mt-1 text-xs font-medium text-slate-600">{{ $channel->payment_details }}
                                            </p>
                                            @if($channel->reference_note)
                                                <p class="mt-1 text-[11px] italic text-slate-400">{{ $channel->reference_note }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if($showPendingStatusNotice)
                    <div class="scroll-reveal reveal-right revealed rounded-2xl border border-amber-200/80 bg-amber-50/70 p-4 shadow-sm"
                        x-intersect.once="$el.classList.add('revealed')">
                        <p
                            class="inline-flex items-center gap-1.5 rounded-full border border-amber-500/25 bg-amber-500/10 px-3 py-1 text-xs font-semibold text-amber-800">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            {{ __('Status Institusi: Menunggu Semakan') }}
                        </p>
                        <p class="mt-2 text-xs leading-relaxed text-amber-900/80">
                            {{ __('Institusi ini masih dalam status semakan. Sila berhati-hati dengan sebarang info / pautan peribadi.') }}
                            </p>
                        </div>
                @endif

                <x-join-ilmu360-cta />

                <div class="scroll-reveal reveal-right revealed rounded-2xl border border-slate-200/70 bg-slate-50/80 p-4 shadow-sm"
                    x-intersect.once="$el.classList.add('revealed')">
                    @php($institutionContributionRouteSegment = \App\Enums\ContributionSubjectType::Institution->publicRouteSegment())
                    <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-slate-400">{{ __('Bantu Semak Rekod') }}</p>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        {{ __('Jumpa maklumat yang perlu diperbetulkan atau rekod yang meragukan?') }}
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <a href="{{ route('contributions.suggest-update', ['subjectType' => $institutionContributionRouteSegment, 'subjectId' => $institution->slug]) }}" wire:navigate
                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-sky-200 hover:bg-sky-50 hover:text-sky-700">
                            {{ __('Cadang Kemaskini') }}
                        </a>
                        <a href="{{ route('reports.create', ['subjectType' => $institutionContributionRouteSegment, 'subjectId' => $institution->slug]) }}" wire:navigate
                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700">
                            {{ __('Lapor') }}
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div x-show="shareModalOpen" x-cloak x-transition.opacity @keydown.escape.window="shareModalOpen = false"
        class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm" aria-modal="true" role="dialog">
        <div class="flex min-h-screen items-center justify-center p-4 sm:p-6">
            <div @click.away="shareModalOpen = false" x-show="shareModalOpen"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-8 scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                x-transition:leave-end="opacity-0 translate-y-8 scale-95"
                class="w-full max-w-lg overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200/50">

                <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-5">
                    <h3 class="font-heading text-xl font-bold text-slate-900">{{ __('Kongsi Institusi') }}</h3>
                    <button type="button" @click="shareModalOpen = false"
                        class="inline-flex size-10 items-center justify-center rounded-full bg-white text-slate-500 shadow-sm ring-1 ring-slate-200 transition hover:bg-slate-50 hover:text-slate-700">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-6 p-6 sm:p-8">
                    <article
                        class="overflow-hidden rounded-2xl border border-slate-200/60 bg-white shadow-lg shadow-slate-200/40">
                        <div class="relative h-56 overflow-hidden bg-slate-100">
                            <img src="{{ $sharePreviewImage }}" alt="{{ $institution->name }}"
                                class="size-full {{ $sharePreviewHasCover ? 'object-cover' : 'object-contain' }}"
                                loading="lazy">
                            <div class="absolute inset-0 bg-gradient-to-t from-slate-900/55 to-transparent"></div>
                        </div>
                        <div class="p-5">
                            <h4 class="font-heading text-lg font-bold leading-tight text-slate-900">
                                {{ $institution->name }}
                            </h4>
                            @if($locationString)
                                <p class="mt-2 flex items-center gap-1.5 text-sm font-medium text-emerald-600">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                        stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                    <span class="truncate">{{ $locationString }}</span>
                                </p>
                            @endif
                        </div>
                    </article>

                    <div class="grid grid-cols-2 gap-4">
                        <button type="button" @click="nativeShare()"
                            class="inline-flex items-center justify-center gap-2 rounded-2xl bg-emerald-600 px-4 py-3.5 text-sm font-bold text-white transition hover:bg-emerald-700">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684l6.632 3.316m-6.632-6l6.632-3.316" />
                            </svg>
                            {{ __('Share Now') }}
                        </button>
                        <button type="button" @click="copyLink()"
                            class="inline-flex items-center justify-center gap-2 rounded-2xl border-2 border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-700 transition hover:border-emerald-500 hover:text-emerald-700">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M8 16h8M8 12h8m-6-8H6a2 2 0 00-2 2v12a2 2 0 002 2h8a2 2 0 002-2v-4" />
                            </svg>
                            {{ __('Copy Link') }}
                        </button>
                    </div>

                    <div x-show="copied" x-transition:enter="transition ease-out duration-300"
                        x-transition:enter-start="opacity-0 -translate-y-2"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        class="flex items-center justify-center gap-2 rounded-xl bg-emerald-50 py-2 text-sm font-bold text-emerald-600">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                        {{ __('Pautan disalin ke papan klip!') }}
                    </div>

                    <div>
                        <p
                            class="mb-4 flex items-center justify-center gap-1.5 text-center text-xs font-bold uppercase tracking-widest text-slate-400">
                            <svg class="h-3.5 w-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
                            </svg>
                            <span>{{ __('Or share via') }}</span>
                        </p>
                        <div class="grid grid-cols-4 gap-3">
                            <a href="{{ $shareLinks['whatsapp'] }}" target="_blank" rel="noopener" title="WhatsApp"
                                class="inline-flex h-12 items-center justify-center rounded-2xl border border-slate-200/60 bg-slate-50 transition hover:-translate-y-1 hover:border-[#25D366] hover:bg-[#25D366]/10">
                                <img src="{{ asset('storage/social-media-icons/whatsapp.svg') }}" alt="WhatsApp"
                                    class="h-6 w-6" loading="lazy">
                            </a>
                            <a href="{{ $shareLinks['telegram'] }}" target="_blank" rel="noopener" title="Telegram"
                                class="inline-flex h-12 items-center justify-center rounded-2xl border border-slate-200/60 bg-slate-50 transition hover:-translate-y-1 hover:border-[#0088cc] hover:bg-[#0088cc]/10">
                                <img src="{{ asset('storage/social-media-icons/telegram.svg') }}" alt="Telegram"
                                    class="h-6 w-6" loading="lazy">
                            </a>
                            <a href="{{ $shareLinks['threads'] }}" target="_blank" rel="noopener" title="Threads"
                                class="inline-flex h-12 items-center justify-center rounded-2xl border border-slate-200/60 bg-slate-50 transition hover:-translate-y-1 hover:border-black hover:bg-black/10">
                                <img src="{{ asset('storage/social-media-icons/threads.svg') }}" alt="Threads" class="h-6 w-6"
                                    loading="lazy">
                            </a>
                            <a href="{{ $shareLinks['facebook'] }}" target="_blank" rel="noopener" title="Facebook"
                                class="inline-flex h-12 items-center justify-center rounded-2xl border border-slate-200/60 bg-slate-50 transition hover:-translate-y-1 hover:border-[#1877F2] hover:bg-[#1877F2]/10">
                                <img src="{{ asset('storage/social-media-icons/facebook.svg') }}" alt="Facebook"
                                    class="h-6 w-6" loading="lazy">
                            </a>
                            <a href="{{ $shareLinks['x'] }}" target="_blank" rel="noopener" title="X"
                                class="inline-flex h-12 items-center justify-center rounded-2xl border border-slate-200/60 bg-slate-50 transition hover:-translate-y-1 hover:border-slate-900 hover:bg-slate-900/10">
                                <img src="{{ asset('storage/social-media-icons/x.svg') }}" alt="X" class="h-6 w-6"
                                    loading="lazy">
                            </a>
                            <a href="{{ $shareLinks['instagram'] }}" target="_blank" rel="noopener" @click="copyLink(false, 'instagram')"
                                title="Instagram"
                                class="inline-flex h-12 items-center justify-center rounded-2xl border border-slate-200/60 bg-slate-50 transition hover:-translate-y-1 hover:border-[#E4405F] hover:bg-[#E4405F]/10">
                                <img src="{{ asset('storage/social-media-icons/instagram.svg') }}" alt="Instagram"
                                    class="h-6 w-6" loading="lazy">
                            </a>
                            <a href="{{ $shareLinks['tiktok'] }}" target="_blank" rel="noopener" @click="copyLink(false, 'tiktok')"
                                title="TikTok"
                                class="inline-flex h-12 items-center justify-center rounded-2xl border border-slate-200/60 bg-slate-50 transition hover:-translate-y-1 hover:border-black hover:bg-black/10">
                                <img src="{{ asset('storage/social-media-icons/tiktok.svg') }}" alt="TikTok"
                                    class="h-6 w-6" loading="lazy">
                            </a>
                            <a href="{{ $shareLinks['email'] }}" title="Email"
                                class="inline-flex h-12 items-center justify-center rounded-2xl border border-slate-200/60 bg-slate-50 transition hover:-translate-y-1 hover:border-emerald-500 hover:bg-emerald-500/10">
                                <img src="{{ asset('storage/social-media-icons/email.svg') }}" alt="Email"
                                    class="h-6 w-6" loading="lazy">
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ─── QR MODAL ─── --}}
    <div x-show="qrModalOpen" x-cloak x-transition.opacity @keydown.escape.window="qrModalOpen = false"
        class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/80 backdrop-blur-md"
        aria-modal="true" role="dialog">
        <div class="relative w-full max-w-lg p-4" @click.away="qrModalOpen = false">
            <div x-show="qrModalOpen" x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                class="overflow-hidden rounded-3xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-bold text-slate-900" x-text="qrActiveLabel"></h3>
                    <button type="button" @click="qrModalOpen = false"
                        class="inline-flex size-8 items-center justify-center rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200 hover:text-slate-700">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="flex items-center justify-center bg-slate-50 p-6 sm:p-10">
                    <img :src="qrActiveUrl" :alt="qrActiveLabel"
                        class="aspect-square w-full max-w-xs object-contain"
                        loading="lazy">
                </div>
                <div class="bg-white p-4 text-center">
                    <p class="text-xs font-medium text-slate-500">{{ __('Imbas kod QR ini untuk membuat sumbangan.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
