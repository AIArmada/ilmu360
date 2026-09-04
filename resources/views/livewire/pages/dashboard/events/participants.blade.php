@php
    $occurrenceOptions = $this->occurrenceOptions();
    $sessionOptions = $this->sessionOptions();
@endphp

<main
    x-data
    x-on:print-participant-list.window="window.print()"
    class="min-h-screen bg-[#fbf8f1] px-6 py-10 lg:px-12"
>
    <div class="mx-auto max-w-7xl space-y-8">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <a
                    href="{{ route('events.show', $event) }}"
                    wire:navigate
                    class="text-sm font-semibold text-emerald-800 hover:text-emerald-950 print:hidden"
                >
                    ← {{ __('Back to event') }}
                </a>
                <div class="mt-5 flex flex-wrap items-start gap-4">
                    <div class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-[#0b2a42] text-xl font-bold text-white">
                        {{ mb_strtoupper(mb_substr((string) $event->title, 0, 1)) }}
                    </div>
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">{{ __('Event workspace') }}</p>
                        <h1 class="mt-2 font-heading text-3xl font-bold tracking-tight text-[#0b2a42] sm:text-4xl">{{ $event->title }}</h1>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Search participants, help them at the door, or record attendance after the event.') }}</p>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 print:hidden">
                @if($isCheckInEnabled)
                    <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-800">
                        <span class="size-2 rounded-full bg-emerald-500"></span>
                        {{ __('Live check-in enabled') }}
                    </span>
                @else
                    <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-2 text-xs font-bold text-slate-600">
                        {{ __('Manual attendance mode') }}
                    </span>
                @endif
                @if($canManageAdmissions && $this->refundsEnabled($event))
                    <a
                        href="{{ route('dashboard.events.refunds', $event) }}"
                        wire:navigate
                        data-signal-event="navigation.event_refunds_opened"
                        data-signal-category="navigation"
                        data-signal-component="event_participants"
                        data-signal-control="refunds"
                        data-signal-entity-type="event"
                        data-signal-entity-id="{{ $event->id }}"
                        class="inline-flex items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-100"
                    >
                        {{ __('Refunds') }}
                    </a>
                @endif
                <button
                    type="button"
                    wire:click="printList"
                    data-signal-event="event.participant_list_print_requested"
                    data-signal-category="event_operations"
                    data-signal-component="event_participants"
                    data-signal-control="print_list"
                    data-signal-entity-type="event"
                    data-signal-entity-id="{{ $event->id }}"
                    class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
                >
                    {{ __('Print list') }}
                </button>
                <a
                    href="{{ $this->exportUrl() }}"
                    data-signal-event="event.participant_export_requested"
                    data-signal-category="event_operations"
                    data-signal-component="event_participants"
                    data-signal-control="export_csv"
                    data-signal-entity-type="event"
                    data-signal-entity-id="{{ $event->id }}"
                    class="inline-flex items-center justify-center rounded-xl bg-[#0b2a42] px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#123e5f]"
                >
                    {{ __('Export CSV') }}
                </a>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach([
                ['label' => __('Participants'), 'value' => $stats['total'] ?? 0, 'class' => 'text-[#0b2a42]'],
                ['label' => __('Attended'), 'value' => $stats['attended'] ?? 0, 'class' => 'text-emerald-700'],
                ['label' => __('Did not attend'), 'value' => $stats['did_not_attend'] ?? 0, 'class' => 'text-amber-700'],
                ['label' => __('Not recorded'), 'value' => $stats['not_recorded'] ?? 0, 'class' => 'text-slate-500'],
            ] as $stat)
                <div class="rounded-3xl border border-[#eadfca] bg-white p-5 shadow-sm">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">{{ $stat['label'] }}</p>
                    <p class="mt-2 text-3xl font-bold {{ $stat['class'] }}">{{ number_format((int) $stat['value']) }}</p>
                </div>
            @endforeach
        </div>

        <section class="rounded-3xl border border-[#eadfca] bg-white p-5 shadow-sm sm:p-6 print:hidden">
            <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_180px_180px_minmax(0,1fr)] xl:items-end">
                <div>
                    <label for="participant-search" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Find a participant') }}</label>
                    <input
                        id="participant-search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        data-signal-event="event.participant_search_used"
                        data-signal-category="event_operations"
                        data-signal-component="event_participants"
                        data-signal-control="search"
                        data-signal-entity-type="event"
                        data-signal-entity-id="{{ $event->id }}"
                        class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100"
                        placeholder="{{ __('Name, email, phone, IC, passport, order or registration no.') }}"
                    >
                </div>
                <div>
                    <label for="attendance-filter" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Attendance') }}</label>
                    <select id="attendance-filter" wire:model.live="attendanceFilter" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                        <option value="all">{{ __('Everyone') }}</option>
                        <option value="attended">{{ __('Attended') }}</option>
                        <option value="did_not_attend">{{ __('Did not attend') }}</option>
                        <option value="not_recorded">{{ __('Not recorded') }}</option>
                    </select>
                </div>
                <div>
                    <label for="attendance-scope" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Scope') }}</label>
                    <select id="attendance-scope" wire:model.live="scope" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                        <option value="event">{{ __('Whole event') }}</option>
                        <option value="occurrence">{{ __('Occurrence') }}</option>
                        <option value="session">{{ __('Session') }}</option>
                    </select>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                    @if($scope !== 'event')
                        <div>
                            <label for="attendance-occurrence" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Occurrence date') }}</label>
                            <select id="attendance-occurrence" wire:model.live="occurrenceId" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                                @forelse($occurrenceOptions as $occurrenceKey => $occurrenceLabel)
                                    <option value="{{ $occurrenceKey }}">{{ $occurrenceLabel }}</option>
                                @empty
                                    <option value="">{{ __('No occurrence') }}</option>
                                @endforelse
                            </select>
                        </div>
                    @endif
                    @if($scope === 'session')
                        <div>
                            <label for="attendance-session" class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Session') }}</label>
                            <select id="attendance-session" wire:model.live="sessionId" class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100">
                                @forelse($sessionOptions as $sessionKey => $sessionLabel)
                                    <option value="{{ $sessionKey }}">{{ $sessionLabel }}</option>
                                @empty
                                    <option value="">{{ __('No session') }}</option>
                                @endforelse
                            </select>
                        </div>
                    @endif
                </div>
            </div>
            <p class="mt-4 text-xs leading-5 text-slate-500">
                {{ __('Search is also able to match a collected IC or passport number. Sensitive identity values remain masked and are not shown in this list.') }}
            </p>
        </section>

        @if($canManageAttendance && count($selectedParticipantIds) > 0)
            <section class="rounded-3xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm print:hidden">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('Bulk attendance') }}</p>
                        <p class="mt-1 text-sm font-semibold text-emerald-950">{{ trans_choice(':count participant selected|:count participants selected', count($selectedParticipantIds), ['count' => count($selectedParticipantIds)]) }}</p>
                    </div>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div>
                            <label for="attendance-note" class="text-xs font-bold uppercase tracking-[0.14em] text-emerald-800">{{ __('Optional note') }}</label>
                            <input id="attendance-note" type="text" wire:model="attendanceNote" maxlength="500" class="mt-2 h-10 w-full rounded-xl border border-emerald-200 bg-white px-3 text-sm outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 sm:w-72" placeholder="{{ __('For example: verified from printed list') }}">
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="markSelectedAttendance('attended')" class="rounded-xl bg-emerald-700 px-3 py-2.5 text-xs font-bold text-white hover:bg-emerald-800">{{ __('Mark attended') }}</button>
                            <button type="button" wire:click="markSelectedAttendance('did_not_attend')" class="rounded-xl border border-amber-300 bg-white px-3 py-2.5 text-xs font-bold text-amber-800 hover:bg-amber-50">{{ __('Did not attend') }}</button>
                            <button type="button" wire:click="markSelectedAttendance('not_recorded')" class="rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50">{{ __('Clear') }}</button>
                            <button type="button" wire:click="clearSelection" class="rounded-xl px-3 py-2.5 text-xs font-bold text-slate-600 hover:bg-white">{{ __('Cancel') }}</button>
                        </div>
                    </div>
                </div>
            </section>
        @endif

        <section class="overflow-hidden rounded-3xl border border-[#eadfca] bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div>
                    <h2 class="font-heading text-2xl font-bold text-[#0b2a42]">{{ __('Participant list') }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ __('Use the event scope for an overall record, or choose a date/session for detailed attendance.') }}</p>
                </div>
                @if($canManageAttendance && $participants->count() > 0)
                    <div class="flex items-center gap-2 print:hidden">
                        <button type="button" wire:click="selectVisibleParticipants" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">{{ __('Select visible') }}</button>
                        <button type="button" wire:click="clearSelection" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-500 hover:bg-slate-50">{{ __('Clear selection') }}</button>
                    </div>
                @endif
            </div>

            @if($participants->count() > 0)
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100 text-left">
                        <thead class="bg-slate-50/70">
                            <tr class="text-[11px] font-bold uppercase tracking-[0.14em] text-slate-500">
                                @if($canManageAttendance)<th class="w-12 px-5 py-4 sm:px-6"><span class="sr-only">{{ __('Select') }}</span></th>@endif
                                <th class="px-5 py-4 sm:px-6">{{ __('Participant') }}</th>
                                <th class="px-5 py-4">{{ __('Registration') }}</th>
                                <th class="px-5 py-4">{{ __('Identity') }}</th>
                                <th class="px-5 py-4">{{ __('Attendance') }}</th>
                                @if($canManageAttendance)<th class="px-5 py-4 print:hidden">{{ __('Actions') }}</th>@endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($participants as $participant)
                                @php
                                    $status = $this->attendanceStatus($participant);
                                    $email = $participant->contactMethods->firstWhere('type', 'email')?->value;
                                    $phone = $participant->contactMethods->firstWhere('type', 'phone')?->value;
                                @endphp
                                <tr wire:key="event-participant-{{ $participant->id }}" class="align-top hover:bg-slate-50/50">
                                    @if($canManageAttendance)
                                        <td class="px-5 py-5 sm:px-6 print:hidden">
                                            <input type="checkbox" wire:model.live="selectedParticipantIds" value="{{ $participant->id }}" class="size-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                        </td>
                                    @endif
                                    <td class="px-5 py-5 sm:px-6">
                                        <p class="font-semibold text-slate-900">{{ $participant->name }}</p>
                                        <div class="mt-1 space-y-0.5 text-xs text-slate-500">
                                            @if($email)<p>{{ $email }}</p>@endif
                                            @if($phone)<p>{{ $phone }}</p>@endif
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-5 text-sm text-slate-600">
                                        <p class="font-semibold text-slate-800">{{ $participant->registration?->registration_no ?? __('No registration no.') }}</p>
                                        @if($participant->registration?->external_order_id)
                                            <p class="mt-1 text-xs text-slate-400">{{ __('Order') }}: {{ $participant->registration->external_order_id }}</p>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-5 text-sm text-slate-600">
                                        {{ $this->participantIdentityLabel($participant) ?? __('Not collected') }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-5">
                                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold
                                            {{ $status === 'attended' ? 'bg-emerald-100 text-emerald-800' : ($status === 'did_not_attend' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600') }}">
                                            {{ $this->attendanceLabel($status) }}
                                        </span>
                                    </td>
                                    @if($canManageAttendance)
                                        <td class="min-w-64 px-5 py-5 print:hidden">
                                            <div class="flex flex-wrap gap-2">
                                                @if($isCheckInEnabled)
                                                    <button
                                                        type="button"
                                                        wire:click="checkInParticipant('{{ $participant->id }}')"
                                                        data-signal-event="event.participant_checkin_requested"
                                                        data-signal-category="event_operations"
                                                        data-signal-component="event_participants"
                                                        data-signal-control="check_in"
                                                        data-signal-entity-type="event_participant"
                                                        data-signal-entity-id="{{ $participant->id }}"
                                                        class="rounded-lg bg-emerald-700 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-800"
                                                    >
                                                        {{ __('Check in') }}
                                                    </button>
                                                @endif
                                                <button type="button" wire:click="markParticipantAttendance('{{ $participant->id }}', 'attended')" class="rounded-lg border border-emerald-200 px-3 py-2 text-xs font-bold text-emerald-800 hover:bg-emerald-50">{{ __('Attended') }}</button>
                                                <button type="button" wire:click="markParticipantAttendance('{{ $participant->id }}', 'did_not_attend')" class="rounded-lg border border-amber-200 px-3 py-2 text-xs font-bold text-amber-800 hover:bg-amber-50">{{ __('Absent') }}</button>
                                                <button type="button" wire:click="markParticipantAttendance('{{ $participant->id }}', 'not_recorded')" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50">{{ __('Clear') }}</button>
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-slate-100 px-5 py-4 sm:px-6 print:hidden">
                    {{ $participants->links() }}
                </div>
            @else
                <div class="px-6 py-16 text-center">
                    <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-emerald-50 text-2xl text-emerald-700">⌕</div>
                    <h3 class="mt-5 font-heading text-2xl font-bold text-[#0b2a42]">{{ __('No participants found') }}</h3>
                    <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">{{ __('Try a different search, attendance filter, or scope.') }}</p>
                </div>
            @endif
        </section>

        <p class="text-xs leading-5 text-slate-500 print:hidden">
            {{ __('Attendance changes are recorded with the staff member, time, scope, previous status, new status, and optional note. They do not change admission or recording access.') }}
        </p>
    </div>
</main>
