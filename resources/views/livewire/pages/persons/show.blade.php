@php
    $personShareImageUrl = $person->public_avatar_url;
@endphp

@section('title', $person->formatted_name . ' - ' . config('app.name'))
@section('meta_description', \Illuminate\Support\Str::limit((is_array($person->bio) ? \Filament\Forms\Components\RichEditor\RichContentRenderer::make($person->bio)->toText() : trim(strip_tags((string) $person->bio))) ?: __('Lihat profil, biodata, dan jadual majlis oleh :name di :app.', ['name' => $person->formatted_name, 'app' => config('app.name')]), 160))
@section('meta_robots', ($person->status === 'verified') ? 'index, follow' : 'noindex, nofollow')
@section('og_url', route('persons.show', $person))
@section('og_image', $personShareImageUrl)
@section('og_image_alt', __('Profil penceramah :name', ['name' => $person->formatted_name]))

@php
    $bioRenderer = is_array($person->bio)
        ? \Filament\Forms\Components\RichEditor\RichContentRenderer::make($person->bio)
        : null;
    $bioHtml = is_array($person->bio) ? $bioRenderer?->toHtml() ?? '' : (string) $person->bio;
    $bioText = is_array($person->bio) ? trim($bioRenderer?->toText() ?? '') : trim(strip_tags((string) $person->bio));
    $shouldCollapseBio = \Illuminate\Support\Str::length($bioText) > 680;
    $locationString = \App\Support\Location\AddressHierarchyFormatter::format($person->primaryAddress());
    $upcomingEvents = $this->upcomingEvents;
    $pastEvents = $this->pastEvents;
    $upcomingTotal = $this->upcomingTotal;
    $pastTotal = $this->pastTotal;
    $otherRoleParticipations = $this->otherRoleParticipations;
    $personUrl = route('persons.show', $person);
    $shareText = trim($person->formatted_name . ' - ' . config('app.name'));
    $shareLinks = app(\App\Services\ShareTrackingService::class)->redirectLinks(
        $personUrl,
        $shareText,
        $person->formatted_name,
    );
    $shareData = [
        'title' => $person->formatted_name,
        'text' => $bioText !== '' ? \Illuminate\Support\Str::limit($bioText, 180) : __('Lihat profil penceramah ini di :app', ['app' => config('app.name')]),
        'url' => $personUrl,
        'sourceUrl' => $personUrl,
        'shareText' => $shareText,
        'fallbackTitle' => $person->formatted_name,
        'payloadEndpoint' => route('dawah-share.payload'),
    ];
    $publicContacts = $person->contactMethods
        ->where('is_public', true)
        ->values();
    $formatContactHref = static function ($contact): ?string {
        return match ((string) $contact->type) {
            'phone', 'mobile' => 'tel:' . preg_replace('/\D+/', '', (string) $contact->value),
            'whatsapp' => 'https://wa.me/' . preg_replace('/\D+/', '', (string) $contact->value),
            'email' => 'mailto:' . (string) $contact->value,
            default => null,
        };
    };
    $socialLinks = $person->socialProfiles
        ->filter(function ($social): bool {
            $resolvedUrl = $social->profileUrl() ?? $social->url;

            return filled($social->platform) && filled($resolvedUrl);
        })
        ->values();
    $socialPresentation = [
        'facebook' => [
            'label' => 'Facebook',
            'icon' => 'facebook.svg',
            'border' => 'group-hover:border-[#1877F2]/35',
        ],
        'instagram' => [
            'label' => 'Instagram',
            'icon' => 'instagram.svg',
            'border' => 'group-hover:border-[#E4405F]/35',
        ],
        'youtube' => [
            'label' => 'YouTube',
            'icon' => 'youtube.svg',
            'border' => 'group-hover:border-[#FF0000]/35',
        ],
        'tiktok' => [
            'label' => 'TikTok',
            'icon' => 'tiktok.svg',
            'border' => 'group-hover:border-slate-400',
        ],
        'telegram' => [
            'label' => 'Telegram',
            'icon' => 'telegram.svg',
            'border' => 'group-hover:border-[#229ED9]/35',
        ],
        'whatsapp' => [
            'label' => 'WhatsApp',
            'icon' => 'whatsapp.svg',
            'border' => 'group-hover:border-[#25D366]/35',
        ],
        'x' => [
            'label' => 'X',
            'icon' => 'x.svg',
            'border' => 'group-hover:border-slate-400',
        ],
        'linkedin' => [
            'label' => 'LinkedIn',
            'icon' => 'linkedin.svg',
            'border' => 'group-hover:border-[#0A66C2]/35',
        ],
    ];
    $showPendingEventStatusNotice = $upcomingEvents->concat($pastEvents)->contains(
        fn (\App\Models\Event $event): bool => (string) $event->status === 'pending'
    );
    $showCancelledEventStatusNotice = $upcomingEvents->concat($pastEvents)->contains(
        fn (\App\Models\Event $event): bool => (string) $event->status === 'cancelled'
    );
    $personRouteSegment = \App\Enums\ContributionSubjectType::Person->publicRouteSegment();

    $resolveEventCategoryLabel = static fn (\App\Models\Event $event): string => app(\App\Support\Events\EventCategoryPresenter::class)->forEvent($event)[0]['path'] ?? __('Umum');

    $resolveEventLocation = static function (\App\Models\Event $event): string {
        $primaryLocationName = $event->venue?->name ?: $event->institution?->name;
        $address = $event->venue?->primaryAddress() ?? $event->institution?->primaryAddress();
        $parts = \App\Support\Location\AddressHierarchyFormatter::parts($address);

        $locationParts = array_filter([
            $primaryLocationName,
            ...$parts,
        ]);

        return implode(', ', $locationParts);
    };

    $resolveKeyPersonRoleLabel = static function (\App\Models\EventKeyPerson $keyPerson): string {
        $role = $keyPerson->role_code;

        if ($role instanceof \App\Enums\EventKeyPersonRole) {
            return $role->getLabel();
        }

        if (is_string($role) && $role !== '') {
            return \App\Enums\EventKeyPersonRole::tryFrom($role)?->getLabel() ?? \Illuminate\Support\Str::headline($role);
        }

        return '';
    };
@endphp

<div class="min-h-screen bg-[#f7f6f1] text-slate-900">
    {{-- Individual person profile hero --}}
    <section class="relative isolate overflow-hidden border-b border-emerald-950/10 bg-[#f4efe4]">
        <div class="absolute inset-0 -z-20 bg-[radial-gradient(circle_at_12%_15%,rgba(201,154,55,0.18),transparent_28%),radial-gradient(circle_at_88%_8%,rgba(5,98,76,0.18),transparent_34%),linear-gradient(135deg,#fffdf8_0%,#f3eee2_55%,#e6eee8_100%)]"></div>
        <div class="absolute inset-0 -z-10 opacity-[0.24]" style="background-image: radial-gradient(circle at 1px 1px, rgba(6,78,59,.22) 1px, transparent 0); background-size: 26px 26px;"></div>
        <div class="absolute -right-24 -top-28 -z-10 h-96 w-96 rounded-full border border-emerald-900/10"></div>
        <div class="absolute -right-8 -top-12 -z-10 h-72 w-72 rounded-full border border-amber-700/10"></div>

        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-8 lg:px-8 lg:py-10">
            <a
                href="{{ route('persons.index') }}"
                wire:navigate
                class="inline-flex items-center gap-2 text-sm font-semibold text-emerald-800 transition hover:text-emerald-600"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" />
                </svg>
                {{ __('Kembali ke Direktori Penceramah') }}
            </a>

            <div class="mt-6 overflow-hidden rounded-[2rem] border border-white/80 bg-white/82 shadow-[0_30px_90px_-42px_rgba(6,78,59,0.42)] backdrop-blur-xl">
                <div class="grid lg:grid-cols-[20rem_minmax(0,1fr)]">
                    <div class="relative min-h-[20rem] overflow-hidden bg-gradient-to-br from-emerald-100 via-[#f4efe4] to-amber-100 lg:min-h-[24rem]">
                        <div class="absolute inset-0 opacity-40" style="background-image: radial-gradient(circle at 1px 1px, rgba(7,91,72,.2) 1px, transparent 0); background-size: 20px 20px;"></div>
                        <img
                            src="{{ $person->public_main_url }}"
                            alt="{{ $person->formatted_name }}"
                            class="relative h-full w-full object-cover object-top"
                            loading="eager"
                        >
                        <div class="absolute inset-x-0 bottom-0 h-40 bg-gradient-to-t from-emerald-950/80 via-emerald-950/25 to-transparent"></div>

                        <div class="absolute inset-x-5 bottom-5 flex items-center justify-between gap-3">
                            @if($person->status === 'verified')
                                <span class="inline-flex items-center gap-2 rounded-full border border-white/30 bg-white/92 px-3 py-1.5 text-xs font-bold text-emerald-800 shadow-lg backdrop-blur">
                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.051l-7.5 9.75a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.897 3.896 6.976-9.07a.75.75 0 0 1 1.051-.142Z" clip-rule="evenodd" />
                                    </svg>
                                    {{ __('Profil Disahkan') }}
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-full border border-white/20 bg-slate-950/45 px-3 py-1.5 text-xs font-semibold text-white backdrop-blur">
                                    {{ __('Profil Penceramah') }}
                                </span>
                            @endif
                        </div>
                    </div>

                    <div class="flex flex-col p-6 sm:p-8 lg:p-8">
                        <div class="flex flex-1 flex-col">
                            <div>
                                <p class="text-[11px] font-black uppercase tracking-[0.24em] text-amber-700">
                                    {{ __('Penceramah ilmu360°') }}
                                </p>

                                <h1 class="mt-3 max-w-3xl font-heading text-4xl font-bold leading-[1.05] tracking-[-0.035em] text-emerald-950 sm:text-5xl lg:text-6xl">
                                    {{ $person->formatted_name }}
                                </h1>

                                @if($locationString !== '')
                                    <p class="mt-4 flex items-start gap-2 text-sm leading-6 text-slate-600 sm:text-base">
                                        <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                                        </svg>
                                        <span>{{ $locationString }}</span>
                                    </p>
                                @endif

                                @if($bioText !== '')
                                    <div class="mt-4">
                                        <div class="flex items-center justify-between gap-4">
                                            <p class="text-[10px] font-black uppercase tracking-[0.22em] text-amber-700">{{ __('Biodata') }}</p>
                                            @if($shouldCollapseBio)
                                                <span class="shrink-0 text-[11px] font-semibold text-slate-500">{{ __('Skrol untuk membaca') }}</span>
                                            @endif
                                        </div>
                                        <div class="mt-2 h-24 max-h-24 overflow-y-auto overscroll-contain pr-4 [scrollbar-color:#a7d5c7_transparent] [scrollbar-width:thin] prose prose-slate max-w-none leading-8 prose-headings:font-heading prose-headings:text-emerald-950 prose-a:text-emerald-700 prose-strong:text-slate-900">
                                            {!! $bioHtml !!}
                                        </div>
                                    </div>
                                @endif

                                <div class="mt-4 grid grid-cols-2 gap-3 border-t border-emerald-950/10 pt-4 sm:max-w-md">
                                    <div class="rounded-2xl border border-emerald-100 bg-emerald-50/70 p-3 text-center">
                                        <p class="font-heading text-2xl font-bold text-emerald-950">{{ number_format($upcomingTotal) }}</p>
                                        <p class="mt-1 text-[10px] font-semibold text-emerald-700">{{ __('Majlis akan datang') }}</p>
                                    </div>
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-3 text-center">
                                        <p class="font-heading text-2xl font-bold text-emerald-950">{{ number_format($pastTotal) }}</p>
                                        <p class="mt-1 text-[10px] font-semibold text-slate-500">{{ __('Majlis lepas') }}</p>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                                <button
                                    type="button"
                                    wire:click="toggleFollow"
                                    wire:loading.attr="disabled"
                                    class="inline-flex h-12 items-center justify-center gap-2 rounded-2xl bg-emerald-800 px-6 text-sm font-bold text-white shadow-lg shadow-emerald-900/15 transition hover:-translate-y-0.5 hover:bg-emerald-700 disabled:cursor-wait disabled:opacity-70"
                                >
                                    <svg class="h-5 w-5" fill="{{ $this->isFollowing ? 'currentColor' : 'none' }}" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185v15.065L12 16.197l-7.5 4.375V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0 1 11.186 0Z" />
                                    </svg>
                                    <span wire:loading.remove wire:target="toggleFollow">
                                        @if($this->isFollowing)
                                            {{ __('Mengikuti Penceramah') }}
                                        @else
                                            {{ __('Ikuti Penceramah') }}
                                        @endif
                                    </span>
                                    <span wire:loading wire:target="toggleFollow">{{ __('Memproses...') }}</span>
                                </button>

                                <a
                                    href="#person-share-panel"
                                    class="inline-flex h-12 items-center justify-center gap-2 rounded-2xl border border-emerald-200 bg-white px-6 text-sm font-bold text-emerald-800 transition hover:-translate-y-0.5 hover:border-emerald-300 hover:bg-emerald-50"
                                >
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5Zm9.566-3.75a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5Zm0 14.25a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5ZM9.164 8.197l5.672-3.144m-5.672 5.75 5.672 3.144" />
                                    </svg>
                                    {{ __('Kongsi Profil') }}
                                </a>

                                @if(auth()->user()?->hasAnyRole(['super_admin', 'admin']))
                                    <a
                                        href="{{ \App\Filament\Resources\Persons\PersonResource::getUrl('edit', ['record' => $person], panel: 'admin') }}"
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

    {{-- Person-specific profile content --}}
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <main class="min-w-0 space-y-8">
                <section class="scroll-reveal reveal-up revealed">
                    <div class="flex flex-col gap-5">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-[0.22em] text-amber-700">{{ __('Jadual Penceramah') }}</p>
                                <h2 class="mt-1 font-heading text-3xl font-bold text-emerald-950">{{ __('Majlis Akan Datang') }}</h2>
                                <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('Majlis yang dijadualkan menampilkan penceramah ini.') }}</p>
                            </div>

                            @if($upcomingEvents->isNotEmpty())
                                <span class="inline-flex w-fit shrink-0 items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-800">
                                    <span class="relative flex h-2 w-2">
                                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                                        <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-600"></span>
                                    </span>
                                    {{ trans_choice(
                                        $upcomingDateFilter === 'all' ? ':count majlis aktif' : ':count majlis dipaparkan',
                                        $upcomingTotal,
                                        ['count' => number_format($upcomingTotal)]
                                    ) }}
                                </span>
                            @endif
                        </div>

                        <div class="flex w-full justify-center">
                            <div class="flex max-w-full flex-wrap items-center justify-center gap-3">
                                <div class="max-w-full overflow-x-auto">
                                    <flux:radio.group
                                        variant="segmented"
                                        size="sm"
                                        wire:model.live="upcomingDateFilter"
                                        wire:loading.attr="disabled"
                                        wire:target="upcomingDateFilter,applyCustomDateRange,clearUpcomingDateFilter"
                                        aria-label="{{ __('Tapis majlis akan datang') }}"
                                        data-signal-event="navigation.upcoming_date_filter_changed"
                                        data-signal-component="person_detail_upcoming_events"
                                        data-signal-control="date_filter"
                                        class="w-max"
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

                                <span
                                    class="flex size-7 shrink-0 items-center justify-center"
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
                    </div>

                    <div class="mt-5">
                        <x-public.moderation-status-note
                            :show-pending="$showPendingEventStatusNotice"
                            :show-cancelled="$showCancelledEventStatusNotice"
                        />
                    </div>

                    <div
                        class="mt-5 space-y-4"
                        wire:loading.class="opacity-60"
                        wire:loading.attr="aria-busy"
                        wire:target="upcomingDateFilter,applyCustomDateRange,clearUpcomingDateFilter"
                    >
                        @foreach($upcomingEvents as $event)
                            @php
                                $eventTypeLabel = $resolveEventCategoryLabel($event);
                                $eventLocation = $resolveEventLocation($event);
                                $eventFormatValue = $event->delivery_mode?->value ?? $event->delivery_mode;
                                $isRemoteEvent = in_array($eventFormatValue, ['online', 'hybrid'], true);
                                $isPendingEvent = (string) $event->status === 'pending';
                                $isCancelledEvent = (string) $event->status === 'cancelled';
                            @endphp

                            <a
                                href="{{ route('events.show', $event) }}"
                                wire:key="upcoming-{{ $event->id }}"
                                wire:navigate
                                class="group block overflow-hidden rounded-[1.4rem] border border-slate-200 bg-white shadow-sm transition duration-300 hover:-translate-y-0.5 hover:border-emerald-300 hover:shadow-[0_20px_45px_-30px_rgba(6,78,59,0.45)]"
                            >
                                <div class="grid sm:grid-cols-[7.5rem_minmax(0,1fr)]">
                                    <div class="flex items-center gap-4 bg-gradient-to-br {{ $isCancelledEvent ? 'from-rose-700 to-rose-950' : ($isPendingEvent ? 'from-amber-600 to-amber-900' : ($isRemoteEvent ? 'from-sky-700 to-sky-950' : 'from-emerald-700 to-emerald-950')) }} px-5 py-4 text-white sm:flex-col sm:justify-center sm:gap-0 sm:px-3 sm:py-6 sm:text-center">
                                        <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-white/70">
                                            {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'l') }}
                                        </span>
                                        <span class="font-heading text-4xl font-black leading-none sm:mt-1">
                                            {{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'd') }}
                                        </span>
                                        <span class="text-xs font-bold uppercase tracking-[0.12em] text-white/80 sm:mt-1">
                                            {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'F') }}
                                        </span>
                                    </div>

                                    <div class="p-5 sm:p-6">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-100">
                                                {{ $eventTypeLabel }}
                                            </span>

                                            @if($isRemoteEvent)
                                                <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-sky-800 ring-1 ring-sky-100">
                                                    {{ __('Dalam Talian') }}
                                                </span>
                                            @endif

                                            @if($isPendingEvent)
                                                <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-amber-800 ring-1 ring-amber-100">
                                                    {{ __('Menunggu Kelulusan') }}
                                                </span>
                                            @endif

                                            @if($isCancelledEvent)
                                                <span class="inline-flex items-center rounded-full bg-rose-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-rose-800 ring-1 ring-rose-100">
                                                    {{ __('Dibatalkan') }}
                                                </span>
                                            @endif
                                        </div>

                                        <h3 class="mt-3 font-heading text-xl font-bold leading-tight text-emerald-950 transition group-hover:text-emerald-700 sm:text-2xl">
                                            {{ $event->title }}
                                        </h3>

                                        @if($event->reference_study_subtitle)
                                            <p class="mt-2 text-sm font-semibold italic text-slate-500">
                                                {{ $event->reference_study_subtitle }}
                                            </p>
                                        @endif

                                        <div class="mt-4 grid gap-2 text-sm text-slate-500">
                                            <p class="flex items-center gap-2">
                                                <svg class="h-4 w-4 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                                </svg>
                                                <span>
                                                    {{ $event->timing_display !== '' ? $event->timing_display : \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'h:i A') }}
                                                    @if($event->ends_at)
                                                        <span class="mx-1 text-slate-300">—</span>
                                                        {{ \App\Support\Timezone\UserDateTimeFormatter::format($event->ends_at, 'h:i A') }}
                                                    @endif
                                                </span>
                                            </p>

                                            @if($eventLocation !== '' && ! $isRemoteEvent)
                                                <p class="flex items-start gap-2">
                                                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                                                    </svg>
                                                    <span class="line-clamp-2">{{ $eventLocation }}</span>
                                                </p>
                                            @endif
                                        </div>

                                        <div class="mt-5 flex items-center justify-between border-t border-slate-100 pt-4">
                                            <span class="text-xs font-semibold text-slate-400">{{ __('Lihat maklumat penuh majlis') }}</span>
                                            <span class="grid h-9 w-9 place-items-center rounded-full bg-emerald-50 text-emerald-700 transition group-hover:translate-x-1 group-hover:bg-emerald-700 group-hover:text-white">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5 5 5-5 5" />
                                                </svg>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        @endforeach

                        @if($upcomingEvents->isEmpty())
                            <div class="rounded-[1.5rem] border border-dashed border-emerald-200 bg-gradient-to-br from-white to-emerald-50/70 p-8 text-center sm:p-10">
                                <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-emerald-100 text-emerald-700">
                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3.75 8.25h16.5m-16.5 0V19.5A1.5 1.5 0 0 0 5.25 21h13.5a1.5 1.5 0 0 0 1.5-1.5V8.25m-16.5 0V6.75a1.5 1.5 0 0 1 1.5-1.5h13.5a1.5 1.5 0 0 1 1.5 1.5v1.5" />
                                    </svg>
                                </span>
                                <h3 class="mt-4 font-heading text-xl font-bold text-emerald-950">
                                    {{ $upcomingDateFilter === 'all' ? __('Belum ada majlis akan datang') : __('Tiada majlis untuk tempoh ini') }}
                                </h3>
                                <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">
                                    {{ $upcomingDateFilter === 'all'
                                        ? __('Ikuti penceramah ini untuk mengetahui apabila jadual majlis baharu diterbitkan.')
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
                    </div>
                </section>

                @if($pastEvents->isNotEmpty())
                    <section class="scroll-reveal reveal-up revealed overflow-hidden rounded-[1.75rem] border border-slate-200 bg-white shadow-sm">
                        <div class="flex items-center justify-between gap-4 border-b border-slate-100 px-6 py-5 sm:px-8">
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">{{ __('Arkib Penglibatan') }}</p>
                                <h2 class="mt-1 font-heading text-2xl font-bold text-emerald-950">{{ __('Majlis Lepas') }}</h2>
                            </div>
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">
                                {{ number_format($pastTotal) }}
                            </span>
                        </div>

                        <div class="divide-y divide-slate-100">
                            @foreach($pastEvents as $event)
                                @php
                                    $eventLocation = $resolveEventLocation($event);
                                    $eventFormatValue = $event->delivery_mode?->value ?? $event->delivery_mode;
                                    $isRemoteEvent = in_array($eventFormatValue, ['online', 'hybrid'], true);
                                @endphp

                                <a
                                    href="{{ route('events.show', $event) }}"
                                    wire:key="past-{{ $event->id }}"
                                    wire:navigate
                                    class="group grid gap-4 px-6 py-5 transition hover:bg-emerald-50/40 sm:grid-cols-[5rem_minmax(0,1fr)_auto] sm:items-center sm:px-8"
                                >
                                    <div class="flex w-fit items-center gap-2 sm:block sm:text-center">
                                        <span class="font-heading text-2xl font-bold text-slate-700">
                                            {{ \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'd') }}
                                        </span>
                                        <span class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400 sm:block">
                                            {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'M Y') }}
                                        </span>
                                    </div>

                                    <div class="min-w-0">
                                        <h3 class="font-heading text-lg font-bold text-slate-800 transition group-hover:text-emerald-700">
                                            {{ $event->title }}
                                        </h3>
                                        <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                                            <span>{{ $event->timing_display !== '' ? $event->timing_display : \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'h:i A') }}</span>
                                            @if($eventLocation !== '' && ! $isRemoteEvent)
                                                <span class="line-clamp-1">{{ $eventLocation }}</span>
                                            @elseif($isRemoteEvent)
                                                <span>{{ __('Dalam talian') }}</span>
                                            @endif
                                        </p>
                                    </div>

                                    <span class="hidden h-9 w-9 place-items-center rounded-full border border-slate-200 text-slate-400 transition group-hover:border-emerald-300 group-hover:bg-emerald-700 group-hover:text-white sm:grid">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5 5 5-5 5" />
                                        </svg>
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if($otherRoleParticipations->isNotEmpty())
                    <section class="scroll-reveal reveal-up revealed rounded-[1.75rem] border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                        <p class="text-[10px] font-black uppercase tracking-[0.22em] text-amber-700">{{ __('Penglibatan Penceramah') }}</p>
                        <h2 class="mt-1 font-heading text-2xl font-bold text-emerald-950">{{ __('Peranan Lain Dalam Majlis') }}</h2>

                        <div class="mt-5 grid gap-3 sm:grid-cols-2">
                            @foreach($otherRoleParticipations as $keyPerson)
                                @php
                                    $event = $keyPerson->event;
                                    $roleLabel = $resolveKeyPersonRoleLabel($keyPerson);
                                @endphp

                                @if($event)
                                    <a
                                        href="{{ route('events.show', $event) }}"
                                        wire:key="role-participation-{{ $keyPerson->id }}"
                                        wire:navigate
                                        class="group rounded-2xl border border-slate-200 bg-slate-50/70 p-4 transition hover:border-emerald-300 hover:bg-emerald-50"
                                    >
                                        <span class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-amber-800">
                                            {{ $roleLabel }}
                                        </span>
                                        <p class="mt-3 font-heading text-base font-bold text-slate-800 transition group-hover:text-emerald-700">
                                            {{ $event->title }}
                                        </p>
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    </section>
                @endif
            </main>

            <aside class="space-y-6 lg:sticky lg:top-24 lg:self-start">
                @if($publicContacts->isNotEmpty())
                    <section class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-[10px] font-black uppercase tracking-[0.22em] text-amber-700">{{ __('Hubungi Penceramah') }}</p>
                        <h2 class="mt-1 font-heading text-xl font-bold text-emerald-950">{{ __('Maklumat Hubungan') }}</h2>
                        <div class="mt-4 space-y-2">
                            @foreach($publicContacts as $contact)
                                @php $contactHref = $formatContactHref($contact); @endphp
                                <div class="rounded-xl border border-slate-200 px-4 py-3"><p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">{{ \Illuminate\Support\Str::headline((string) $contact->type) }}</p>@if($contactHref)<a href="{{ $contactHref }}" class="mt-1 block break-all text-sm font-semibold text-slate-700 transition hover:text-emerald-800">{{ $contact->value }}</a>@else<p class="mt-1 break-all text-sm font-semibold text-slate-700">{{ $contact->value }}</p>@endif</div>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if($socialLinks->isNotEmpty())
                    <section class="rounded-[1.5rem] border border-emerald-100 bg-white p-5 shadow-sm">
                        <p class="text-[10px] font-black uppercase tracking-[0.22em] text-amber-700">{{ __('Pautan Penceramah') }}</p>
                        <h2 class="mt-1 font-heading text-xl font-bold text-emerald-950">{{ __('Media Sosial Rasmi') }}</h2>
                        <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                            @foreach($socialLinks as $social)
                                @php $platform = strtolower((string) $social->platform); $presentation = $socialPresentation[$platform] ?? ['label' => \Illuminate\Support\Str::headline($platform), 'icon' => 'link.svg', 'border' => 'group-hover:border-emerald-300']; $handle = trim((string) ($social->handle ?? '')); $handleLabel = $handle !== '' ? (str_starts_with($handle, '@') ? $handle : '@'.$handle) : __('Lihat profil rasmi'); @endphp
                                <a href="{{ $social->profileUrl() ?? $social->url }}" target="_blank" rel="noopener noreferrer" class="group flex min-w-0 items-center gap-3 rounded-2xl border border-slate-200/90 bg-slate-50/45 px-3 py-3 transition hover:bg-white hover:text-emerald-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/10 {{ $presentation['border'] }}"><img src="{{ asset('storage/social-media-icons/'.$presentation['icon']) }}" alt="" class="h-10 w-10 shrink-0 object-contain" loading="lazy"><span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold text-slate-800 group-hover:text-emerald-800">{{ $presentation['label'] }}</span><span class="mt-0.5 block truncate text-xs text-slate-500">{{ $handleLabel }}</span></span></a>
                            @endforeach
                        </div>
                    </section>
                @endif

                <section id="person-share-panel" class="scroll-reveal reveal-right revealed">
                    <x-dawah-share-panel
                        :heading="__('Kongsi Penceramah')"
                        description=""
                        :share-data="$shareData"
                        :share-links="$shareLinks"
                    />
                </section>

                <section class="scroll-reveal reveal-right revealed rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">{{ __('Ketepatan Maklumat') }}</p>
                    <h2 class="mt-1 font-heading text-lg font-bold text-emerald-950">{{ __('Bantu Semak Profil Ini') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">
                        {{ __('Nampak maklumat yang tidak tepat atau profil yang meragukan? Bantu komuniti dengan memaklumkan kepada kami.') }}
                    </p>

                    <div class="mt-4 grid gap-2">
                        <a
                            href="{{ route('contributions.suggest-update', ['subjectType' => $personRouteSegment, 'subjectId' => $person->slug]) }}"
                            wire:navigate
                            class="group inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-sky-200 bg-sky-50 px-4 text-xs font-bold text-sky-800 transition hover:-translate-y-0.5 hover:border-sky-300 hover:bg-sky-100 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-sky-600/10"
                        >
                            <svg class="h-4 w-4 shrink-0 transition-transform group-hover:-rotate-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 2.652 2.652M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                            </svg>
                            {{ __('Cadangkan Kemaskini') }}
                        </a>
                        <a
                            href="{{ route('reports.create', ['subjectType' => $personRouteSegment, 'subjectId' => $person->slug]) }}"
                            wire:navigate
                            class="group inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-4 text-xs font-bold text-rose-800 transition hover:-translate-y-0.5 hover:border-rose-300 hover:bg-rose-100 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rose-600/10"
                        >
                            <svg class="h-4 w-4 shrink-0 transition-transform group-hover:-rotate-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18m0-16.5c5.25-3 10.5 3 15.75 0v9c-5.25 3-10.5-3-15.75 0" />
                            </svg>
                            {{ __('Laporkan Profil') }}
                        </a>
                    </div>
                </section>

                <x-sidebar-inspiration />
            </aside>
        </div>
    </div>
</div>
