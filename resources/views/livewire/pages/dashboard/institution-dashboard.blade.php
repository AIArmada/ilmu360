@section('title', ($this->eventListPage ? __('Event List') : __('Manage Institution')) . ' - ' . config('app.name'))

@php
    $institutions = $this->institutions;
    $selectedInstitution = $this->selectedInstitution;
    $members = $this->institutionMembers;
    $memberRoleMap = $this->institutionMemberRoleMap;
    $institutionRoleOptions = $this->institutionRoleOptions;
    $canManageMembers = $this->canManageMembers;
    $canUseSelectedInstitutionForScopedSubmission = $this->canUseSelectedInstitutionForScopedSubmission;
    $stats = $this->institutionStats;
    $recentEvents = $this->recentInstitutionEvents;
    $pendingEvents = $this->pendingInstitutionEvents;
    $isEventListPage = $this->eventListPage;
    $translateRoleLabel = static function (string $role): string {
        $label = str($role)->replace('_', ' ')->headline()->toString();
        $translated = __($label);

        return $translated !== $label ? $translated : $label;
    };
    $translateStatusLabel = static function (mixed $status): string {
        if ($status instanceof \BackedEnum) {
            if (method_exists($status, 'getLabel')) {
                return (string) $status->getLabel();
            }

            if (method_exists($status, 'label')) {
                return (string) $status->label();
            }

            $status = $status->value;
        }

        if (! is_scalar($status)) {
            return '';
        }

        $status = (string) $status;
        $translated = __($status);

        return $translated !== $status
            ? $translated
            : str($status)->replace('_', ' ')->headline()->toString();
    };
    $statusClass = static fn (string $status): string => match ($status) {
        'approved' => 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-100',
        'pending', 'needs_changes' => 'bg-amber-50 text-amber-800 ring-1 ring-amber-100',
        'cancelled', 'rejected' => 'bg-rose-50 text-rose-800 ring-1 ring-rose-100',
        'draft' => 'bg-slate-100 text-slate-700 ring-1 ring-slate-200',
        default => 'bg-slate-100 text-slate-700 ring-1 ring-slate-200',
    };
    $eventDateParts = static function (\App\Models\Event $event): array {
        if (! $event->starts_at instanceof \Carbon\CarbonInterface) {
            return [
                'day' => '--',
                'month' => __('TBC'),
                'weekday' => '',
            ];
        }

        return [
            'day' => \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'd'),
            'month' => \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'M'),
            'weekday' => \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'D'),
        ];
    };
    $eventTimeLabel = static function (\App\Models\Event $event): string {
        if (! $event->starts_at instanceof \Carbon\CarbonInterface) {
            return __('Time to be confirmed');
        }

        if ($event->isPrayerRelative()) {
            return (string) $event->timing_display;
        }

        $start = \App\Support\Timezone\UserDateTimeFormatter::format($event->starts_at, 'h:i A');

        if (! $event->ends_at instanceof \Carbon\CarbonInterface) {
            return $start;
        }

        return $start.' - '.\App\Support\Timezone\UserDateTimeFormatter::format($event->ends_at, 'h:i A');
    };
    $eventLocationLabel = static fn (\App\Models\Event $event): string => $event->space?->name
        ?? $event->venue?->name
        ?? $event->institution?->display_name
        ?? __('Location to be confirmed');
    $canEditInstitution = $selectedInstitution !== null && (auth()->user()?->can('update', $selectedInstitution) ?? false);
    $institutionEditUrl = $canEditInstitution
        ? route('contributions.suggest-update', [
            'subjectType' => \App\Enums\ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $selectedInstitution->slug,
        ])
        : null;
    $ahliInstitutionInvitationsUrl = $selectedInstitution !== null && $canManageMembers
        ? \App\Filament\Ahli\Resources\Institutions\InstitutionResource::getUrl('edit', ['record' => $selectedInstitution, 'relation' => 'member_invitations'], panel: 'ahli')
        : null;
    $institutionSubmitUrl = $selectedInstitution !== null && $canUseSelectedInstitutionForScopedSubmission
        ? route('dashboard.institutions.submit-event', ['institution' => $selectedInstitution->id])
        : null;
    $institutionDashboardUrl = $selectedInstitution !== null
        ? route('dashboard.institutions', ['institution' => $selectedInstitution->id])
        : route('dashboard.institutions');
    $institutionEventsUrl = $selectedInstitution !== null
        ? route('dashboard.institutions.events', ['institution' => $selectedInstitution->id])
        : route('dashboard.institutions.events');
    $institutionImageUrl = $selectedInstitution?->public_cover_url ?: asset('images/default-mosque-hero.png');
    $dashboardStats = [
        [
            'label' => __('Upcoming Majlis'),
            'value' => $stats['upcoming_events_count'],
            'description' => __('Next 30 days'),
            'icon' => 'heroicon-o-calendar-days',
            'tone' => 'bg-emerald-50 text-emerald-800',
        ],
        [
            'label' => __('Drafts'),
            'value' => $stats['draft_events_count'],
            'description' => __('Saved majlis'),
            'icon' => 'heroicon-o-document-text',
            'tone' => 'bg-amber-50 text-amber-800',
        ],
        [
            'label' => __('Waiting Review'),
            'value' => $stats['pending_events_count'],
            'description' => __('Needs follow-through'),
            'icon' => 'heroicon-o-user-group',
            'tone' => 'bg-teal-50 text-teal-800',
        ],
        [
            'label' => __('Followers'),
            'value' => $stats['followers_count'],
            'description' => __('Community reach'),
            'icon' => 'heroicon-o-users',
            'tone' => 'bg-slate-100 text-slate-800',
        ],
    ];
    $summaryStats = [
        [
            'label' => __('Public Majlis'),
            'value' => $stats['public_events_count'],
            'description' => __('Visible to the community'),
            'icon' => 'heroicon-o-megaphone',
            'tone' => 'bg-emerald-50 text-emerald-800',
        ],
        [
            'label' => __('Registrations'),
            'value' => $stats['registrations_count'],
            'description' => __('All institution majlis'),
            'icon' => 'heroicon-o-ticket',
            'tone' => 'bg-sky-50 text-sky-800',
        ],
        [
            'label' => __('Private or Draft'),
            'value' => $stats['internal_events_count'],
            'description' => __('Internal workspace'),
            'icon' => 'heroicon-o-lock-closed',
            'tone' => 'bg-amber-50 text-amber-800',
        ],
        [
            'label' => __('Members'),
            'value' => $stats['members_count'],
            'description' => __('Institution access'),
            'icon' => 'heroicon-o-identification',
            'tone' => 'bg-rose-50 text-rose-800',
        ],
    ];
@endphp

@if($isEventListPage)
    @include('partials.filament-assets', [
        'scripts' => ['filament/tables'],
    ])
@endif

<div class="min-h-screen bg-[#f8f7f2] pt-8 pb-16 sm:pt-10">
    <div class="container mx-auto px-4 sm:px-6 lg:px-12">
        <div class="mx-auto max-w-7xl space-y-8">
            @if(!$selectedInstitution)
                <section class="rounded-lg border border-dashed border-slate-300 bg-white p-10 text-center">
                    <p class="text-lg font-semibold text-slate-700">{{ __('You do not have institution access yet.') }}</p>
                    <p class="mt-2 text-sm text-slate-500">{{ __('Ask an institution owner or admin to add you as a member to unlock this dashboard.') }}</p>
                </section>
            @else
                <section class="relative overflow-hidden rounded-lg border border-emerald-950/10 bg-[#fffcf4] shadow-sm">
                    <img src="{{ $institutionImageUrl }}" alt="" class="pointer-events-none absolute bottom-0 right-0 hidden h-full w-1/2 object-cover opacity-15 lg:block">
                    <div class="pointer-events-none absolute inset-0 bg-[size:280px] opacity-[0.035]" style="background-image: url('{{ asset('images/pattern-bg.png') }}');"></div>

                    <div class="relative grid gap-6 p-5 sm:p-7 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,24rem)] lg:items-start lg:p-8">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2 text-sm font-semibold text-emerald-900">
                                <span class="inline-flex size-9 items-center justify-center rounded-full bg-emerald-900 text-white shadow-sm">
                                    <x-filament::icon icon="heroicon-o-building-library" class="h-5 w-5" />
                                </span>
                                <span>{{ $isEventListPage ? __('Event List') : __('Institution Dashboard') }}</span>
                            </div>

                            <h1 class="mt-5 break-words font-heading text-4xl font-bold leading-tight text-emerald-950 sm:text-5xl lg:text-6xl">
                                {{ $isEventListPage ? __('Event List') : __('Manage Institution') }}
                            </h1>

                            <div class="mt-4 flex min-w-0 flex-wrap items-center gap-2 text-base text-emerald-950">
                                <x-filament::icon icon="heroicon-o-map-pin" class="h-5 w-5 shrink-0 text-emerald-800" />
                                <span class="truncate font-semibold">{{ $selectedInstitution->display_name }}</span>
                            </div>

                            <p class="mt-5 max-w-2xl text-base leading-7 text-slate-700">
                                {{ $isEventListPage ? __('Filter the event list by title, status, visibility, or sort order so urgent work is easier to spot.') : __('Review institution profile, events, and members in one place.') }}
                            </p>

                            <div class="mt-6 flex flex-wrap gap-3">
                                @if($isEventListPage)
                                    <a
                                        href="{{ $institutionDashboardUrl }}"
                                        wire:navigate
                                        class="inline-flex h-11 items-center justify-center gap-2 rounded-lg border border-emerald-200 bg-white/90 px-4 text-sm font-semibold text-emerald-800 shadow-sm transition hover:bg-emerald-50"
                                    >
                                        <x-filament::icon icon="heroicon-o-arrow-left" class="h-5 w-5" />
                                        {{ __('Back to Institution Dashboard') }}
                                    </a>
                                @endif

                                @if($institutionSubmitUrl)
                                    <a
                                        href="{{ $institutionSubmitUrl }}"
                                        wire:navigate
                                        data-signal-event="submission.institution_event_start_clicked"
                                        data-signal-category="submission"
                                        data-signal-component="institution_dashboard"
                                        data-signal-control="add_event"
                                        data-signal-entity-type="institution"
                                        data-signal-entity-id="{{ $selectedInstitution->id }}"
                                        data-signal-props='@json(['entry_point' => 'hero'])'
                                        class="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-emerald-800 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-900"
                                    >
                                        <x-filament::icon icon="heroicon-o-plus-circle" class="h-5 w-5" />
                                        {{ __('Add Event') }}
                                    </a>
                                @endif

                                @if($institutionEditUrl)
                                    <a
                                        href="{{ $institutionEditUrl }}"
                                        wire:navigate
                                        class="inline-flex h-11 items-center justify-center gap-2 rounded-lg border border-emerald-200 bg-white/90 px-4 text-sm font-semibold text-emerald-800 shadow-sm transition hover:bg-emerald-50"
                                    >
                                        <x-filament::icon icon="heroicon-o-pencil-square" class="h-5 w-5" />
                                        {{ __('Edit Institution') }}
                                    </a>
                                @endif
                            </div>
                        </div>

                        <div class="rounded-lg border border-white/70 bg-white/85 p-4 shadow-sm" data-testid="institution-dashboard-picker">
                            <flux:select
                                wire:model.live="institutionId"
                                data-testid="institution-dashboard-select"
                                label="{{ __('Institution') }}"
                                placeholder="{{ __('Select institution') }}"
                                label:class="text-xs font-semibold !text-slate-600"
                                class="h-11 rounded-lg border-slate-300 bg-white !text-slate-900 shadow-xs hover:border-slate-400 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 [&>option]:text-slate-900"
                            >
                                @foreach($institutions as $institution)
                                    <flux:select.option value="{{ $institution->id }}">{{ $institution->name }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                                <div class="rounded-lg bg-emerald-50 p-3 text-emerald-950">
                                    <p class="text-2xl font-bold">{{ number_format($stats['events_count']) }}</p>
                                    <p class="mt-1 text-emerald-900">{{ __('Total Majlis') }}</p>
                                </div>
                                <div class="rounded-lg bg-amber-50 p-3 text-amber-950">
                                    <p class="text-2xl font-bold">{{ number_format($stats['pending_events_count']) }}</p>
                                    <p class="mt-1 text-amber-900">{{ __('Waiting Review') }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                @if($isEventListPage)
                    <section class="space-y-4" aria-labelledby="institution-events">
                        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                            <div>
                                <h2 id="institution-events" class="font-heading text-2xl font-bold text-emerald-950">{{ __('Event List') }}</h2>
                                <p class="mt-1 text-sm text-slate-600">{{ __('Filter the event list by title, status, visibility, or sort order so urgent work is easier to spot.') }}</p>
                            </div>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm sm:p-4">
                            {{ $this->table }}
                        </div>
                    </section>
                @else
                <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="{{ __('Summary Statistics') }}">
                    @foreach($dashboardStats as $stat)
                        <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-start justify-between gap-4">
                                <span class="inline-flex size-12 items-center justify-center rounded-lg {{ $stat['tone'] }}">
                                    <x-filament::icon :icon="$stat['icon']" class="h-6 w-6" />
                                </span>
                                <p class="font-heading text-3xl font-bold text-emerald-950">{{ number_format($stat['value']) }}</p>
                            </div>
                            <p class="mt-4 font-semibold text-emerald-950">{{ $stat['label'] }}</p>
                            <p class="mt-1 text-sm text-slate-600">{{ $stat['description'] }}</p>
                        </div>
                    @endforeach
                </section>

                    <section class="space-y-4" aria-labelledby="institution-recent-events">
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex size-10 items-center justify-center rounded-lg bg-emerald-800 text-white">
                                    <x-filament::icon icon="heroicon-o-calendar" class="h-5 w-5" />
                                </span>
                                <h2 id="institution-recent-events" class="font-heading text-2xl font-bold text-emerald-950">{{ __('Institution Dashboard Majlis') }}</h2>
                            </div>

                            <a
                                href="{{ $institutionEventsUrl }}"
                                wire:navigate
                                data-signal-event="navigation.institution_event_list_opened"
                                data-signal-category="navigation"
                                data-signal-component="institution_dashboard"
                                data-signal-control="view_all_majlis"
                                data-signal-entity-type="institution"
                                data-signal-entity-id="{{ $selectedInstitution->id }}"
                                class="inline-flex items-center gap-1 text-sm font-semibold text-emerald-800 hover:text-emerald-950"
                            >
                                {{ __('Lihat semua majlis') }}
                                <x-filament::icon icon="heroicon-o-chevron-right" class="h-4 w-4" />
                            </a>
                        </div>

                        @if($recentEvents->isEmpty())
                            <div class="rounded-lg border border-dashed border-slate-300 bg-white px-5 py-10 text-center">
                                <p class="font-semibold text-slate-800">{{ __('No majlis found for this institution yet.') }}</p>
                                <p class="mt-2 text-sm text-slate-600">{{ __('Create the first institution majlis so your community can discover it.') }}</p>
                            </div>
                        @else
                            <div class="space-y-3">
                                @foreach($recentEvents as $event)
                                    @php
                                        $statusValue = (string) $event->status;
                                        $dateParts = $eventDateParts($event);
                                        $canEditEvent = auth()->user()?->can('update', $event) ?? false;
                                        $ahliEventEditUrl = $canEditEvent
                                            ? \App\Filament\Ahli\Resources\Events\EventResource::getUrl('edit', ['record' => $event], panel: 'ahli')
                                            : null;
                                        $duplicateEventUrl = $canEditEvent && $canUseSelectedInstitutionForScopedSubmission
                                            ? route('dashboard.institutions.submit-event', ['institution' => $selectedInstitution->id, 'duplicate' => $event->id])
                                            : null;
                                    @endphp

                                    <article class="grid gap-4 rounded-lg border border-slate-200 bg-white p-3 shadow-sm sm:grid-cols-[7.5rem_4.5rem_minmax(0,1fr)] sm:items-center" wire:key="institution-recent-event-{{ $event->id }}">
                                        <a href="{{ route('events.show', $event) }}" wire:navigate class="block overflow-hidden rounded-lg bg-slate-100">
                                            <img src="{{ $event->card_image_url }}" alt="{{ $event->title }}" loading="lazy" class="aspect-[16/10] h-full w-full object-cover transition duration-500 hover:scale-105 sm:aspect-square">
                                        </a>

                                        <div class="flex items-center justify-between rounded-lg bg-emerald-50 px-4 py-3 text-emerald-950 sm:block sm:text-center">
                                            <span class="font-heading text-3xl font-bold leading-none">{{ $dateParts['day'] }}</span>
                                            <span class="text-sm font-semibold">{{ $dateParts['month'] }}</span>
                                            @if($dateParts['weekday'] !== '')
                                                <span class="text-sm text-emerald-800 sm:block">{{ $dateParts['weekday'] }}</span>
                                            @endif
                                        </div>

                                        <div class="min-w-0">
                                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                                <div class="min-w-0">
                                                    <a href="{{ route('events.show', $event) }}" wire:navigate class="font-heading text-xl font-bold leading-tight text-emerald-950 hover:text-emerald-800">
                                                        {{ $event->title }}
                                                    </a>

                                                    <div class="mt-3 flex flex-col gap-2 text-sm text-slate-600 sm:flex-row sm:flex-wrap sm:items-center">
                                                        <span class="inline-flex items-center gap-1.5">
                                                            <x-filament::icon icon="heroicon-o-map-pin" class="h-4 w-4 text-emerald-800" />
                                                            {{ $eventLocationLabel($event) }}
                                                        </span>
                                                        <span class="inline-flex items-center gap-1.5">
                                                            <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4 text-emerald-800" />
                                                            {{ $eventTimeLabel($event) }}
                                                        </span>
                                                    </div>

                                                    @if($statusValue !== 'approved')
                                                        <span class="mt-3 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass($statusValue) }}">
                                                            {{ $translateStatusLabel($statusValue) }}
                                                        </span>
                                                    @endif
                                                </div>

                                                <div class="flex shrink-0 flex-wrap gap-2">
                                                    @if($ahliEventEditUrl)
                                                        <a href="{{ $ahliEventEditUrl }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-50">
                                                            <x-filament::icon icon="heroicon-o-pencil-square" class="h-4 w-4" />
                                                            {{ __('Edit') }}
                                                        </a>
                                                    @endif

                                                    @if($duplicateEventUrl)
                                                        <a href="{{ $duplicateEventUrl }}" wire:navigate class="inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100">
                                                            <x-filament::icon icon="heroicon-o-document-duplicate" class="h-4 w-4" />
                                                            {{ __('Duplicate Event') }}
                                                        </a>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        @endif
                    </section>

                    <section class="space-y-4" aria-labelledby="institution-pending-events">
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex size-10 items-center justify-center rounded-lg bg-emerald-800 text-white">
                                    <x-filament::icon icon="heroicon-o-user-group" class="h-5 w-5" />
                                </span>
                                <h2 id="institution-pending-events" class="font-heading text-2xl font-bold text-emerald-950">{{ __('Waiting Review') }}</h2>
                            </div>

                            <a
                                href="{{ $institutionEventsUrl }}"
                                wire:navigate
                                data-signal-event="navigation.institution_event_list_opened"
                                data-signal-category="navigation"
                                data-signal-component="institution_dashboard"
                                data-signal-control="pending_view_all_majlis"
                                data-signal-entity-type="institution"
                                data-signal-entity-id="{{ $selectedInstitution->id }}"
                                class="inline-flex items-center gap-1 text-sm font-semibold text-emerald-800 hover:text-emerald-950"
                            >
                                {{ __('Lihat semua majlis') }}
                                <x-filament::icon icon="heroicon-o-chevron-right" class="h-4 w-4" />
                            </a>
                        </div>

                        @if($pendingEvents->isEmpty())
                            <div class="rounded-lg border border-slate-200 bg-white px-5 py-6 shadow-sm">
                                <p class="font-semibold text-slate-800">{{ __('No pending review items.') }}</p>
                                <p class="mt-2 text-sm text-slate-600">{{ __('New submissions and changes that need institution follow-through will appear here.') }}</p>
                            </div>
                        @else
                            <div class="grid gap-4 lg:grid-cols-2">
                                @foreach($pendingEvents as $event)
                                    @php
                                        $statusValue = (string) $event->status;
                                        $canEditEvent = auth()->user()?->can('update', $event) ?? false;
                                        $ahliEventEditUrl = $canEditEvent
                                            ? \App\Filament\Ahli\Resources\Events\EventResource::getUrl('edit', ['record' => $event], panel: 'ahli')
                                            : null;
                                    @endphp

                                    <article class="rounded-lg border border-amber-200 bg-white p-4 shadow-sm" wire:key="institution-pending-event-{{ $event->id }}">
                                        <div class="flex items-start justify-between gap-4">
                                            <div class="flex min-w-0 gap-3">
                                                <span class="inline-flex size-12 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-800">
                                                    <x-filament::icon icon="heroicon-o-document-text" class="h-6 w-6" />
                                                </span>
                                                <div class="min-w-0">
                                                    <h3 class="font-heading text-lg font-bold leading-tight text-emerald-950">{{ $event->title }}</h3>
                                                    <p class="mt-1 text-sm text-slate-600">{{ $eventLocationLabel($event) }}</p>
                                                    <span class="mt-3 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass($statusValue) }}">
                                                        {{ $translateStatusLabel($statusValue) }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mt-4 flex flex-wrap gap-2">
                                            @if($ahliEventEditUrl)
                                                <a href="{{ $ahliEventEditUrl }}" class="inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-lg bg-emerald-800 px-3 text-sm font-semibold text-white transition hover:bg-emerald-900">
                                                    <x-filament::icon icon="heroicon-o-check-circle" class="h-4 w-4" />
                                                    {{ __('Review') }}
                                                </a>
                                            @endif

                                            <a href="{{ route('events.show', $event) }}" wire:navigate class="inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-50">
                                                <x-filament::icon icon="heroicon-o-eye" class="h-4 w-4" />
                                                {{ __('View Event') }}
                                            </a>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        @endif
                    </section>

                @if($institutionSubmitUrl)
                    <a
                        href="{{ $institutionSubmitUrl }}"
                        wire:navigate
                        data-signal-event="submission.institution_event_start_clicked"
                        data-signal-category="submission"
                        data-signal-component="institution_dashboard"
                        data-signal-control="add_event"
                        data-signal-entity-type="institution"
                        data-signal-entity-id="{{ $selectedInstitution->id }}"
                        data-signal-props='@json(['entry_point' => 'main_cta'])'
                        class="flex h-14 items-center justify-center gap-3 rounded-lg bg-emerald-800 px-5 text-base font-bold text-white shadow-sm transition hover:bg-emerald-900"
                    >
                        <x-filament::icon icon="heroicon-o-plus-circle" class="h-6 w-6" />
                        {{ __('Add Event') }}
                    </a>
                @endif

                <section class="space-y-4" aria-labelledby="institution-summary-stats">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex size-10 items-center justify-center rounded-lg bg-emerald-800 text-white">
                                <x-filament::icon icon="heroicon-o-chart-bar-square" class="h-5 w-5" />
                            </span>
                            <h2 id="institution-summary-stats" class="font-heading text-2xl font-bold text-emerald-950">{{ __('Summary Statistics') }}</h2>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        @foreach($summaryStats as $stat)
                            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex size-11 items-center justify-center rounded-lg {{ $stat['tone'] }}">
                                        <x-filament::icon :icon="$stat['icon']" class="h-5 w-5" />
                                    </span>
                                    <div>
                                        <p class="font-heading text-2xl font-bold text-emerald-950">{{ number_format($stat['value']) }}</p>
                                        <p class="text-sm font-semibold text-slate-800">{{ $stat['label'] }}</p>
                                    </div>
                                </div>
                                <p class="mt-3 text-sm text-slate-600">{{ $stat['description'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>

                @if($canManageMembers)
                    <section class="space-y-4" aria-labelledby="institution-members">
                        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                            <div>
                                <h2 id="institution-members" class="font-heading text-2xl font-bold text-emerald-950">{{ __('Members & Roles') }}</h2>
                                <p class="mt-1 text-sm text-slate-600">{{ __('Keep institution access up to date and assign the right Ahli roles for each member.') }}</p>
                            </div>

                            @if($ahliInstitutionInvitationsUrl)
                                <a
                                    href="{{ $ahliInstitutionInvitationsUrl }}"
                                    class="inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-emerald-200 bg-white px-3 text-sm font-semibold text-emerald-800 shadow-sm transition hover:bg-emerald-50"
                                >
                                    <x-filament::icon icon="heroicon-o-envelope" class="h-4 w-4" />
                                    {{ __('Manage Invitations') }}
                                </a>
                            @endif
                        </div>

                        <form wire:submit="addMember" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                            <div class="grid gap-4 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_auto] lg:items-end">
                                <div>
                                    <label for="institution-member-email" class="mb-2 block text-xs font-semibold text-slate-600">
                                        {{ __('Email address') }}
                                    </label>
                                    <input
                                        id="institution-member-email"
                                        type="email"
                                        wire:model="newMemberEmail"
                                        placeholder="{{ __('Existing user email') }}"
                                        class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-emerald-500 focus:outline-none"
                                    >
                                    @error('newMemberEmail')
                                        <p class="mt-2 text-xs font-medium text-rose-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="institution-member-roles" class="mb-2 block text-xs font-semibold text-slate-600">
                                        {{ __('Roles') }}
                                    </label>
                                    <select
                                        id="institution-member-roles"
                                        wire:model="newMemberRoleId"
                                        class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none"
                                    >
                                        <option value="">{{ __('Select a role') }}</option>
                                        @foreach($institutionRoleOptions as $roleId => $roleName)
                                            <option value="{{ $roleId }}">{{ $translateRoleLabel($roleName) }}</option>
                                        @endforeach
                                    </select>
                                    @error('newMemberRoleId')
                                        <p class="mt-2 text-xs font-medium text-rose-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <button
                                    type="submit"
                                    class="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-emerald-800 px-4 text-sm font-semibold text-white transition hover:bg-emerald-900"
                                >
                                    <x-filament::icon icon="heroicon-o-user-plus" class="h-4 w-4" />
                                    {{ __('Add Member') }}
                                </button>
                            </div>
                        </form>

                        @if($members->isEmpty())
                            <div class="rounded-lg border border-dashed border-slate-300 bg-white px-5 py-10 text-center">
                                <p class="text-base font-semibold text-slate-700">{{ __('No institution members found yet.') }}</p>
                            </div>
                        @else
                            <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                                <table class="min-w-full divide-y divide-slate-100">
                                    <thead>
                                        <tr class="text-left text-xs font-semibold text-slate-600">
                                            <th class="px-4 py-3">{{ __('Name') }}</th>
                                            <th class="px-4 py-3">{{ __('Email address') }}</th>
                                            <th class="px-4 py-3">{{ __('Roles') }}</th>
                                            @if($canManageMembers)
                                                <th class="px-4 py-3">{{ __('Actions') }}</th>
                                            @endif
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 text-sm text-slate-700">
                                        @foreach($members as $member)
                                            @php
                                                $roleNames = $memberRoleMap[$member->id] ?? [];
                                                $isEditingMember = $canManageMembers && $this->editingMemberId === $member->id;
                                                $isProtectedOwner = in_array('owner', $roleNames, true);
                                            @endphp
                                            <tr wire:key="institution-member-{{ $member->id }}">
                                                <td class="px-4 py-4">
                                                    <div class="font-semibold text-slate-900">{{ $member->name }}</div>
                                                </td>
                                                <td class="px-4 py-4">{{ $member->email }}</td>
                                                <td class="px-4 py-4">
                                                    @if($isEditingMember)
                                                        <div class="max-w-sm">
                                                            <select
                                                                wire:model="editingMemberRoleId"
                                                                class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none"
                                                            >
                                                                <option value="">{{ __('Select a role') }}</option>
                                                                @foreach($institutionRoleOptions as $roleId => $roleName)
                                                                    <option value="{{ $roleId }}">{{ $translateRoleLabel($roleName) }}</option>
                                                                @endforeach
                                                            </select>
                                                            @error('editingMemberRoleId')
                                                                <p class="mt-2 text-xs font-medium text-rose-600">{{ $message }}</p>
                                                            @enderror
                                                        </div>
                                                    @elseif($roleNames !== [])
                                                        <div class="flex flex-wrap gap-2">
                                                            @foreach($roleNames as $roleName)
                                                                <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                                                                    {{ $translateRoleLabel($roleName) }}
                                                                </span>
                                                            @endforeach
                                                        </div>
                                                    @else
                                                        <span class="text-slate-400">{{ __('No roles assigned') }}</span>
                                                    @endif
                                                </td>
                                                @if($canManageMembers)
                                                    <td class="px-4 py-4 align-top">
                                                        <div class="flex flex-wrap gap-2">
                                                            @if($isEditingMember)
                                                                <button
                                                                    type="button"
                                                                    wire:click="saveMemberRoles"
                                                                    class="inline-flex h-9 items-center justify-center rounded-lg bg-emerald-800 px-3 text-xs font-semibold text-white transition hover:bg-emerald-900"
                                                                >
                                                                    {{ __('Save Roles') }}
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    wire:click="cancelEditingMemberRoles"
                                                                    class="inline-flex h-9 items-center justify-center rounded-lg border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                                                                >
                                                                    {{ __('Cancel') }}
                                                                </button>
                                                            @else
                                                                @if(!$isProtectedOwner)
                                                                    <button
                                                                        type="button"
                                                                        wire:click="startEditingMemberRoles('{{ $member->id }}')"
                                                                        class="inline-flex h-9 items-center justify-center rounded-lg border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                                                                    >
                                                                        {{ __('Edit Roles') }}
                                                                    </button>
                                                                    <button
                                                                        type="button"
                                                                        wire:click="removeMember('{{ $member->id }}')"
                                                                        wire:confirm="{{ __('Remove this member from the institution?') }}"
                                                                        class="inline-flex h-9 items-center justify-center rounded-lg border border-rose-200 bg-rose-50 px-3 text-xs font-semibold text-rose-700 transition hover:bg-rose-100"
                                                                    >
                                                                        {{ __('Remove') }}
                                                                    </button>
                                                                @else
                                                                    <span class="inline-flex items-center rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">
                                                                        {{ __('Owner role is managed globally') }}
                                                                    </span>
                                                                @endif
                                                            @endif
                                                        </div>
                                                    </td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div>
                                {{ $members->links(data: ['scrollTo' => '#institution-members']) }}
                            </div>
                        @endif
                    </section>
                @endif
                @endif
            @endif
        </div>
    </div>
</div>
