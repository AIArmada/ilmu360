<div class="min-h-screen bg-[#f7f5ef] text-slate-900">
    @php
        $eventCover = $event->getFirstMedia('cover');
        $occurrenceCover = $occurrence->getFirstMedia('cover');
        $heroMedia = $occurrenceCover ?? $eventCover;
        $heroImageUrl = $heroMedia?->getAvailableUrl(['thumb']) ?: $event->card_image_url;
        $sessions = $occurrence->sessions
            ->filter(fn (\AIArmada\Events\Models\EventSession $session): bool => \App\Support\Events\PublicSchedulePolicy::isMeaningfulSession($session))
            ->values();
        $location = $occurrence->locations->first() ?? $event->primaryLocation;
        $locationName = $location?->venue?->name ?? $event->institution?->name ?? $event->venue?->name;
        $spaceName = \App\Support\Spaces\SpaceLocationPresenter::name($location);
        $locationLabel = collect([$locationName, $spaceName])->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')->implode(' · ');
        $addressModel = $location?->venue?->primaryAddress() ?? $event->institution?->primaryAddress() ?? $event->venue?->primaryAddress();
        $mapUrl = filled($addressModel?->google_maps_url)
            ? (string) $addressModel->google_maps_url
            : (filled($addressModel?->latitude) && filled($addressModel?->longitude)
                ? 'https://www.google.com/maps/dir/?api=1&destination='.$addressModel->latitude.','.$addressModel->longitude
                : null);
        $registrationUrl = route('events.show', $event);
        $description = is_array($event->description) ? implode("\n\n", array_map('strval', $event->description)) : (string) $event->description;
        $registrationMode = $occurrence->registration_mode ?? $event->registration_mode;
        $pricingMode = $occurrence->pricing_mode ?? $event->pricing_mode;
        $registrationModeValue = $registrationMode instanceof \BackedEnum ? $registrationMode->value : (string) $registrationMode;
        $pricingModeValue = $pricingMode instanceof \BackedEnum ? $pricingMode->value : (string) $pricingMode;
        $requiresRegistration = $registrationModeValue === 'required';
        $isFree = $pricingModeValue === 'free';
    @endphp

    <section class="border-b border-emerald-950/10 bg-emerald-950 text-white">
        <div class="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
            <nav class="flex flex-wrap items-center gap-2 text-xs font-semibold text-emerald-100/75" aria-label="{{ __('Breadcrumb') }}">
                <a href="{{ route('events.index') }}" wire:navigate class="transition hover:text-white">{{ __('Majlis') }}</a>
                <span aria-hidden="true">/</span>
                <a href="{{ route('events.show', $event) }}" wire:navigate class="max-w-[18rem] truncate transition hover:text-white">{{ $event->title }}</a>
                <span aria-hidden="true">/</span>
                <span class="text-amber-200">{{ __('Occurrence') }}</span>
            </nav>
        </div>
    </section>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 lg:py-12">
        <div class="grid gap-8 lg:grid-cols-[minmax(0,1.15fr)_minmax(20rem,.85fr)] lg:items-stretch">
            <div class="relative min-h-[22rem] overflow-hidden rounded-[2rem] bg-emerald-950 shadow-[0_28px_80px_-44px_rgba(6,78,59,.7)]">
                @if($heroImageUrl)
                    <img src="{{ $heroImageUrl }}" alt="{{ $occurrence->title ?: $event->title }}" class="absolute inset-0 h-full w-full object-cover">
                    <div class="absolute inset-0 bg-gradient-to-tr from-emerald-950/95 via-emerald-950/35 to-transparent"></div>
                @else
                    <div class="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(251,191,36,.34),transparent_28%),linear-gradient(135deg,#064e3b,#022c22)]"></div>
                    <div class="absolute inset-0 opacity-20" style="background-image: radial-gradient(rgba(255,255,255,.7) 1px, transparent 1px); background-size: 18px 18px;"></div>
                @endif
                <div class="relative flex min-h-[22rem] flex-col justify-end p-7 sm:p-10">
                    <span class="mb-4 inline-flex w-fit items-center rounded-full border border-amber-200/40 bg-amber-100/10 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[.18em] text-amber-100 backdrop-blur">
                        {{ __('Scheduled experience') }}
                    </span>
                    <h1 class="max-w-3xl font-heading text-3xl font-bold leading-tight text-white sm:text-5xl">{{ $occurrence->title ?: $event->title }}</h1>
                    <p class="mt-3 max-w-2xl text-sm font-medium leading-6 text-emerald-50/80">{{ __('Part of :event', ['event' => $event->title]) }}</p>
                </div>
            </div>

            <aside class="flex flex-col justify-between rounded-[2rem] border border-amber-100 bg-white p-6 shadow-sm sm:p-8">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[.18em] text-emerald-700">{{ __('When and where') }}</p>
                    <dl class="mt-6 space-y-5 text-sm">
                        <div class="flex gap-3">
                            <dt class="mt-0.5 text-emerald-700">◷</dt>
                            <dd>
                                <strong class="block text-base text-slate-900">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($occurrence->starts_at, 'l, j F Y') }}</strong>
                                <span class="mt-1 block text-slate-600">{{ \App\Support\Timezone\UserDateTimeFormatter::format($occurrence->starts_at, 'g:i A') }}@if($occurrence->ends_at) – {{ \App\Support\Timezone\UserDateTimeFormatter::format($occurrence->ends_at, 'g:i A') }}@endif</span>
                            </dd>
                        </div>
                        <div class="flex gap-3">
                            <dt class="mt-0.5 text-emerald-700">⌖</dt>
                            <dd>
                                <strong class="block text-base text-slate-900">{{ $locationLabel !== '' ? $locationLabel : __('Location to be announced') }}</strong>
                                @if($addressModel)
                                    <span class="mt-1 block text-slate-600">{{ \App\Support\Location\AddressHierarchyFormatter::format($addressModel, ['city', 'state']) }}</span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>
                <div class="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                    <a href="{{ route('events.show', $event) }}" wire:navigate class="inline-flex items-center justify-center rounded-xl bg-emerald-800 px-5 py-3 text-sm font-bold text-white transition hover:bg-emerald-900">{{ __('View programme') }}</a>
                    @if($mapUrl)
                        <a href="{{ $mapUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center justify-center rounded-xl border border-slate-200 px-5 py-3 text-sm font-bold text-slate-700 transition hover:border-emerald-200 hover:bg-emerald-50">{{ __('Open Maps') }}</a>
                    @endif
                </div>
            </aside>
        </div>

        <div class="mt-8 grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="space-y-8">
                @if($description !== '')
                    <section class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                        <p class="text-xs font-bold uppercase tracking-[.18em] text-emerald-700">{{ __('About this programme') }}</p>
                        <div class="prose prose-slate mt-4 max-w-none text-sm leading-7">{!! nl2br(e($description)) !!}</div>
                    </section>
                @endif

                <section class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[.18em] text-emerald-700">{{ __('Schedule') }}</p>
                            <h2 class="mt-2 font-heading text-2xl font-bold text-emerald-950">{{ $sessions->isNotEmpty() ? __('Sessions in this occurrence') : __('This occurrence') }}</h2>
                        </div>
                        @if($sessions->isNotEmpty())
                            <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-800">{{ $sessions->count() }} {{ __('sessions') }}</span>
                        @endif
                    </div>

                    @if($sessions->isEmpty())
                        <p class="mt-5 text-sm leading-7 text-slate-600">{{ __('This scheduled experience does not have separate sessions. The occurrence timing above is the public schedule.') }}</p>
                    @else
                        <div class="mt-6 space-y-3">
                            @foreach($sessions as $session)
                                @php
                                    $sessionMedia = $session->getFirstMedia('cover') ?? $occurrenceCover ?? $eventCover;
                                    $sessionImageUrl = $sessionMedia?->getAvailableUrl(['thumb']) ?: $event->card_image_url;
                                    $speakers = $session->involvements
                                        ->map(fn ($involvement): string => $involvement->involveable instanceof \App\Models\Person
                                            ? (string) ($involvement->involveable->formatted_name ?? $involvement->involveable->name)
                                            : (string) ($involvement->display_name ?? ''))
                                        ->filter()
                                        ->take(2)
                                        ->implode(', ');
                                @endphp
                                <a href="{{ route('events.session', ['event' => $event, 'occurrenceSlug' => \App\Support\Events\PublicScheduleSlug::occurrence($occurrence), 'sessionSlug' => \App\Support\Events\PublicScheduleSlug::session($session)]) }}" wire:navigate class="group grid gap-4 rounded-2xl border border-slate-200 p-3 transition hover:border-emerald-200 hover:bg-emerald-50/40 sm:grid-cols-[10rem_minmax(0,1fr)]">
                                    <div class="relative aspect-[16/9] overflow-hidden rounded-xl bg-emerald-950">
                                        @if($sessionImageUrl)
                                            <img src="{{ $sessionImageUrl }}" alt="{{ $session->title }}" loading="lazy" class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                                        @else
                                            <div class="flex h-full items-center justify-center text-amber-200"><span class="font-heading text-2xl">∞</span></div>
                                        @endif
                                    </div>
                                    <div class="min-w-0 py-1">
                                        <p class="text-xs font-bold uppercase tracking-[.14em] text-emerald-700">{{ \App\Support\Timezone\UserDateTimeFormatter::format($session->starts_at, 'g:i A') }}</p>
                                        <h3 class="mt-1 font-heading text-lg font-bold leading-tight text-slate-900 group-hover:text-emerald-800">{{ $session->title }}</h3>
                                        @if($speakers !== '')
                                            <p class="mt-2 text-sm text-slate-600">{{ $speakers }}</p>
                                        @endif
                                        @if($session->summary)
                                            <p class="mt-2 line-clamp-2 text-sm leading-6 text-slate-500">{{ $session->summary }}</p>
                                        @endif
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </section>
            </div>

            <aside class="h-fit rounded-[2rem] bg-[#062b49] p-6 text-white shadow-[0_24px_70px_-45px_rgba(6,43,73,.75)] sm:p-7">
                <p class="text-xs font-bold uppercase tracking-[.18em] text-amber-200">{{ __('Access') }}</p>
                <h2 class="mt-3 font-heading text-2xl font-bold">{{ $requiresRegistration ? __('Registration required') : __('Open to attend') }}</h2>
                <p class="mt-3 text-sm leading-6 text-slate-200">{{ $isFree ? __('Free admission') : __('Admission details are available through the programme page.') }}</p>
                <a href="{{ $registrationUrl }}" wire:navigate class="mt-6 inline-flex w-full items-center justify-center rounded-xl bg-amber-300 px-4 py-3 text-sm font-bold text-slate-950 transition hover:bg-amber-200">{{ $requiresRegistration ? __('View registration') : __('View details') }}</a>
            </aside>
        </div>
    </main>
</div>
