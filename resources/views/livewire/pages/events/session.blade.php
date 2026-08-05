<div class="min-h-screen bg-[#f7f5ef] text-slate-900">
    @php
        $eventCover = $event->getFirstMedia('cover');
        $occurrenceCover = $occurrence->getFirstMedia('cover');
        $sessionCover = $session->getFirstMedia('cover');
        $heroMedia = $sessionCover ?? $occurrenceCover ?? $eventCover;
        $heroImageUrl = $heroMedia?->getAvailableUrl(['thumb']) ?: $event->card_image_url;
        $location = $session->locations->first() ?? $occurrence->locations->first() ?? $event->primaryLocation;
        $locationName = $location?->venue?->name ?? $event->institution?->name ?? $event->venue?->name;
        $spaceName = \App\Support\Spaces\SpaceLocationPresenter::name($location);
        $locationLabel = collect([$locationName, $spaceName])->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')->implode(' · ');
        $locationVenue = $location?->venue_id !== null ? \App\Models\Venue::query()->find($location->venue_id) : null;
        $addressModel = $locationVenue?->primaryAddress() ?? $event->institution?->primaryAddress() ?? $event->venue?->primaryAddress();
        $speakers = $session->involvements
            ->map(fn ($involvement): string => $involvement->involveable instanceof \App\Models\Person
                ? (string) ($involvement->involveable->formatted_name ?? $involvement->involveable->name)
                : (string) ($involvement->display_name ?? ''))
            ->filter()
            ->values();
        $description = (string) ($session->description ?: $session->summary ?: '');
        $registrationMode = $session->registration_mode ?? $occurrence->registration_mode ?? $event->registration_mode;
        $pricingMode = $session->pricing_mode ?? $occurrence->pricing_mode ?? $event->pricing_mode;
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
                <a href="{{ route('events.show', $event) }}" wire:navigate class="max-w-[14rem] truncate transition hover:text-white">{{ $event->title }}</a>
                <span aria-hidden="true">/</span>
                <a href="{{ route('events.occurrence', ['event' => $event, 'occurrenceSlug' => \App\Support\Events\PublicScheduleSlug::occurrence($occurrence)]) }}" wire:navigate class="max-w-[14rem] truncate transition hover:text-white">{{ $occurrence->title ?: __('Occurrence') }}</a>
                <span aria-hidden="true">/</span>
                <span class="text-amber-200">{{ __('Session') }}</span>
            </nav>
        </div>
    </section>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 lg:py-12">
        <div class="grid gap-8 lg:grid-cols-[minmax(0,1.1fr)_minmax(20rem,.9fr)]">
            <div class="relative min-h-[24rem] overflow-hidden rounded-[2rem] bg-emerald-950 shadow-[0_28px_80px_-44px_rgba(6,78,59,.7)]">
                @if($heroImageUrl)
                    <img src="{{ $heroImageUrl }}" alt="{{ $session->title }}" class="absolute inset-0 h-full w-full object-cover">
                    <div class="absolute inset-0 bg-gradient-to-tr from-emerald-950/95 via-emerald-950/35 to-transparent"></div>
                @else
                    <div class="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(251,191,36,.34),transparent_28%),linear-gradient(135deg,#064e3b,#022c22)]"></div>
                    <div class="absolute inset-0 opacity-20" style="background-image: radial-gradient(rgba(255,255,255,.7) 1px, transparent 1px); background-size: 18px 18px;"></div>
                @endif
                <div class="relative flex min-h-[24rem] flex-col justify-end p-7 sm:p-10">
                    <span class="mb-4 inline-flex w-fit items-center rounded-full border border-amber-200/40 bg-amber-100/10 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[.18em] text-amber-100 backdrop-blur">{{ __('Session') }}</span>
                    <h1 class="max-w-3xl font-heading text-3xl font-bold leading-tight text-white sm:text-5xl">{{ $session->title }}</h1>
                    <p class="mt-3 text-sm font-medium text-emerald-50/80">{{ __('Within :occurrence', ['occurrence' => $occurrence->title ?: $event->title]) }}</p>
                </div>
            </div>

            <aside class="rounded-[2rem] border border-amber-100 bg-white p-6 shadow-sm sm:p-8">
                <p class="text-xs font-bold uppercase tracking-[.18em] text-emerald-700">{{ __('Session details') }}</p>
                <dl class="mt-6 space-y-5 text-sm">
                    <div class="flex gap-3">
                        <dt class="mt-0.5 text-emerald-700">◷</dt>
                        <dd>
                            <strong class="block text-base text-slate-900">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($session->starts_at, 'l, j F Y') }}</strong>
                            <span class="mt-1 block text-slate-600">{{ \App\Support\Timezone\UserDateTimeFormatter::format($session->starts_at, 'g:i A') }}@if($session->ends_at) – {{ \App\Support\Timezone\UserDateTimeFormatter::format($session->ends_at, 'g:i A') }}@endif</span>
                        </dd>
                    </div>
                    @if($speakers->isNotEmpty())
                        <div class="flex gap-3">
                            <dt class="mt-0.5 text-emerald-700">✦</dt>
                            <dd>
                                <strong class="block text-base text-slate-900">{{ __('With the speaker') }}</strong>
                                <span class="mt-1 block text-slate-600">{{ $speakers->implode(', ') }}</span>
                            </dd>
                        </div>
                    @endif
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
                <div class="mt-8 space-y-3">
                    <a href="{{ route('events.show', $event) }}" wire:navigate class="inline-flex w-full items-center justify-center rounded-xl bg-emerald-800 px-5 py-3 text-sm font-bold text-white transition hover:bg-emerald-900">{{ __('View programme') }}</a>
                    <a href="{{ route('events.occurrence', ['event' => $event, 'occurrenceSlug' => \App\Support\Events\PublicScheduleSlug::occurrence($occurrence)]) }}" wire:navigate class="inline-flex w-full items-center justify-center rounded-xl border border-slate-200 px-5 py-3 text-sm font-bold text-slate-700 transition hover:border-emerald-200 hover:bg-emerald-50">{{ __('View occurrence') }}</a>
                </div>
            </aside>
        </div>

        <div class="mt-8 grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <section class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <p class="text-xs font-bold uppercase tracking-[.18em] text-emerald-700">{{ __('About this session') }}</p>
                <h2 class="mt-2 font-heading text-2xl font-bold text-emerald-950">{{ $session->title }}</h2>
                @if($description !== '')
                    <div class="prose prose-slate mt-5 max-w-none text-sm leading-7">{!! nl2br(e($description)) !!}</div>
                @else
                    <p class="mt-5 text-sm leading-7 text-slate-600">{{ __('Session details will be updated by the programme organiser.') }}</p>
                @endif

                @if($speakers->isNotEmpty())
                    <div class="mt-8 border-t border-slate-100 pt-6">
                        <p class="text-xs font-bold uppercase tracking-[.18em] text-emerald-700">{{ __('Speaker') }}</p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach($speakers as $speaker)
                                <span class="rounded-full bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-900">{{ $speaker }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>

            <aside class="h-fit rounded-[2rem] bg-[#062b49] p-6 text-white shadow-[0_24px_70px_-45px_rgba(6,43,73,.75)] sm:p-7">
                <p class="text-xs font-bold uppercase tracking-[.18em] text-amber-200">{{ __('Access') }}</p>
                <h2 class="mt-3 font-heading text-2xl font-bold">{{ $requiresRegistration ? __('Registration required') : __('Open to attend') }}</h2>
                <p class="mt-3 text-sm leading-6 text-slate-200">{{ $isFree ? __('Free admission') : __('Admission details are available through the programme page.') }}</p>
                <a href="{{ route('events.show', $event) }}" wire:navigate class="mt-6 inline-flex w-full items-center justify-center rounded-xl bg-amber-300 px-4 py-3 text-sm font-bold text-slate-950 transition hover:bg-amber-200">{{ $requiresRegistration ? __('View registration') : __('View details') }}</a>
            </aside>
        </div>
    </main>
</div>
