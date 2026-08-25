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
    $contactPresentation = [
        'phone' => ['label' => __('Telefon'), 'icon' => 'phone', 'tone' => 'bg-emerald-50 text-emerald-700 ring-emerald-100'],
        'mobile' => ['label' => __('Telefon'), 'icon' => 'phone', 'tone' => 'bg-emerald-50 text-emerald-700 ring-emerald-100'],
        'whatsapp' => ['label' => 'WhatsApp', 'icon' => 'whatsapp', 'tone' => 'bg-green-50 text-green-700 ring-green-100'],
        'email' => ['label' => __('E-mel'), 'icon' => 'email', 'tone' => 'bg-amber-50 text-amber-700 ring-amber-100'],
    ];
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
            <x-ui.breadcrumbs
                class="mb-6"
                :items="[
                    ['label' => __('Laman Utama'), 'url' => route('home'), 'icon' => 'home'],
                    ['label' => __('Penceramah'), 'url' => route('persons.index'), 'icon' => 'person', 'show_label' => true],
                ]"
            />

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
                                            <span class="hidden sm:inline">{{ __('Mengikuti Penceramah') }}</span>
                                        @else
                                            <span class="sm:hidden">{{ __('Ikuti') }}</span>
                                            <span class="hidden sm:inline">{{ __('Ikuti Penceramah') }}</span>
                                        @endif
                                    </span>
                                    <span wire:loading wire:target="toggleFollow">{{ __('Memproses...') }}</span>
                                </button>

                                <a
                                    href="#person-share-panel"
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
            <div class="min-w-0 space-y-8">
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
                                        data-signal-component="person_detail_upcoming_events"
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
            </div>

            <aside class="space-y-6 lg:sticky lg:top-24 lg:self-start">
                @if($publicContacts->isNotEmpty())
                    <section class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-[10px] font-black uppercase tracking-[0.22em] text-amber-700">{{ __('Hubungi Penceramah') }}</p>
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

                <x-public-record-feedback
                    share-panel-id="person-share-panel"
                    :subject-type="$personRouteSegment"
                    :subject-id="$person->slug"
                    :share-data="$shareData"
                    :share-links="$shareLinks"
                />

                @if(! $this->hasAdminMember)
                    <section class="rounded-[1.5rem] border border-amber-200 bg-gradient-to-br from-amber-50 via-white to-emerald-50/60 p-5 shadow-sm">
                        <p class="text-[10px] font-black uppercase tracking-[0.22em] text-amber-700">{{ __('Membership') }}</p>
                        <h2 class="mt-1 font-heading text-xl font-bold text-emerald-950">{{ __('Claim Membership') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            {{ __('Claim membership for this :subject', ['subject' => \Illuminate\Support\Str::lower(__('Person'))]) }}
                        </p>
                        <p class="mt-3 text-xs leading-5 text-slate-500">
                            {{ __('Your proof is reviewed first. Access is only added after an admin or moderator approves the claim.') }}
                        </p>
                        <a
                            href="{{ route('membership-applications.create', ['subjectType' => \App\Enums\MemberSubjectType::Person->publicRouteSegment(), 'subjectId' => $person->slug]) }}"
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
