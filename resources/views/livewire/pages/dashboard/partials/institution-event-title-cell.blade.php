@php
    $statusValue = (string) $event->status;
    $isAwaitingApproval = $statusValue === 'pending';
    $canEditEvent = auth()->user()?->can('update', $event) ?? false;
    $ahliEventEditUrl = $canEditEvent
        ? \AIArmada\FilamentEvents\Resources\EventResource::getUrl('edit', ['record' => $event], panel: 'ahli')
        : null;
    $duplicateEventUrl = $canEditEvent && $canUseSelectedInstitutionForScopedSubmission && filled($selectedInstitutionId)
        ? route('dashboard.institutions.submit-event', ['institution' => $selectedInstitutionId, 'duplicate' => $event->id])
        : null;
    $scheduleUrl = $canEditEvent
        ? route('dashboard.events.schedule', ['event' => $event->id])
        : null;
    $registrationQuestionsUrl = $canEditEvent
        ? route('dashboard.events.registration-questions', ['event' => $event->id])
        : null;
    $canViewRegistrations = auth()->user()?->can('viewRegistrations', $event) ?? false;
    $participantsUrl = $canViewRegistrations
        ? route('dashboard.events.participants', ['event' => $event->id])
        : null;
    $canManageAdmissions = auth()->user()?->can('manageAdmissions', $event) ?? false;
    $offlineAdmissionsUrl = $canManageAdmissions
        ? route('dashboard.events.offline-admissions', ['event' => $event->id])
        : null;
@endphp

<div class="{{ $isAwaitingApproval ? 'border-s-4 border-amber-400 ps-4' : '' }}">
    <a
        href="{{ route('events.show', $event) }}"
        wire:navigate
        class="font-semibold {{ $isAwaitingApproval ? 'text-amber-950 hover:text-amber-800' : 'text-slate-900 hover:text-emerald-700' }}"
    >
        {{ $event->title }}
    </a>

    @if($isAwaitingApproval)
        <div class="mt-2">
            <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-[11px] font-semibold text-amber-900 ring-1 ring-amber-300">
                {{ __('Pending Approval') }}
            </span>
        </div>
    @endif

    @if($ahliEventEditUrl || $duplicateEventUrl || $scheduleUrl || $registrationQuestionsUrl || $participantsUrl || $offlineAdmissionsUrl)
        <div class="mt-2 flex flex-wrap items-center gap-2">
            @if($ahliEventEditUrl)
                <a
                    href="{{ $ahliEventEditUrl }}"
                    title="{{ $isAwaitingApproval ? __('Review') : __('Edit') }}"
                    aria-label="{{ $isAwaitingApproval ? __('Review') : __('Edit') }}"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-emerald-200 bg-emerald-50 text-emerald-700 transition hover:bg-emerald-100"
                >
                    <x-filament::icon icon="heroicon-o-pencil-square" class="h-4 w-4" />
                </a>
            @endif

            @if($duplicateEventUrl)
                <a
                    href="{{ $duplicateEventUrl }}"
                    wire:navigate
                    title="{{ __('Duplicate Event') }}"
                    aria-label="{{ __('Duplicate Event') }}"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-amber-200 bg-amber-50 text-amber-700 transition hover:bg-amber-100"
                >
                    <x-filament::icon icon="heroicon-o-document-duplicate" class="h-4 w-4" />
                </a>
            @endif

            @if($scheduleUrl)
                <a href="{{ $scheduleUrl }}" wire:navigate class="text-xs font-semibold text-indigo-700 hover:underline" data-signal-event="navigation.event_schedule_opened" data-signal-category="navigation" data-signal-component="institution_event_list" data-signal-control="schedule" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}">
                    {{ __('Schedule') }}
                </a>
            @endif

            @if($registrationQuestionsUrl)
                <a href="{{ $registrationQuestionsUrl }}" wire:navigate class="text-xs font-semibold text-violet-700 hover:underline" data-signal-event="navigation.event_registration_questions_opened" data-signal-category="navigation" data-signal-component="institution_event_list" data-signal-control="registration_questions" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}">
                    {{ __('Questions') }}
                </a>
            @endif

            @if($participantsUrl)
                <a
                    href="{{ $participantsUrl }}"
                    wire:navigate
                    data-signal-event="navigation.event_participants_opened"
                    data-signal-category="navigation"
                    data-signal-component="institution_event_list"
                    data-signal-control="participants"
                    data-signal-entity-type="event"
                    data-signal-entity-id="{{ $event->id }}"
                    title="{{ __('Participants and attendance') }}"
                    aria-label="{{ __('Participants and attendance') }}"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-sky-200 bg-sky-50 text-sky-700 transition hover:bg-sky-100"
                >
                    <x-filament::icon icon="heroicon-o-user-group" class="h-4 w-4" />
                </a>
            @endif

            @if($offlineAdmissionsUrl)
                <a
                    href="{{ $offlineAdmissionsUrl }}"
                    wire:navigate
                    data-signal-event="navigation.event_offline_admissions_opened"
                    data-signal-category="navigation"
                    data-signal-component="institution_event_list"
                    data-signal-control="offline_admissions"
                    data-signal-entity-type="event"
                    data-signal-entity-id="{{ $event->id }}"
                    title="{{ __('Offline admissions') }}"
                    aria-label="{{ __('Offline admissions') }}"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-amber-200 bg-amber-50 text-amber-700 transition hover:bg-amber-100"
                >
                    <x-filament::icon icon="heroicon-o-banknotes" class="h-4 w-4" />
                </a>
            @endif
        </div>
    @endif
</div>
