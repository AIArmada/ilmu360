@section('title', __('Schedule') . ' · ' . $event->title . ' - ' . config('app.name'))

<main class="min-h-screen bg-[#fbf8f1] px-6 py-10 lg:px-12">
    <div class="mx-auto max-w-6xl space-y-8">
        <header class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <a href="{{ route('dashboard') }}" wire:navigate class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">← {{ __('Dashboard') }}</a>
                <p class="mt-5 text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">{{ __('Event schedule') }}</p>
                <h1 class="mt-2 font-heading text-4xl font-bold tracking-tight text-[#0b2a42]">{{ $event->title }}</h1>
                <p class="mt-2 max-w-3xl text-sm leading-7 text-slate-600">{{ __('Keep dates and sessions in one clear structure. Add another occurrence for another date, then add the sessions that happen inside it.') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('update', $event)
                    <a href="{{ route('dashboard.events.registration-questions', $event) }}" wire:navigate class="rounded-xl border border-violet-200 bg-violet-50 px-4 py-3 text-sm font-semibold text-violet-800 hover:bg-violet-100" data-signal-event="navigation.event_registration_questions_opened" data-signal-category="navigation" data-signal-component="event_schedule" data-signal-control="registration_questions" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}">{{ __('Questions') }}</a>
                @endcan
                @can('viewRegistrations', $event)
                    <a href="{{ route('dashboard.events.participants', $event) }}" wire:navigate class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm font-semibold text-sky-800 hover:bg-sky-100" data-signal-event="navigation.event_participants_opened" data-signal-category="navigation" data-signal-component="event_schedule" data-signal-control="participants">{{ __('Participants') }}</a>
                @endcan
                @can('manageAdmissions', $event)
                    <a href="{{ route('dashboard.events.offline-admissions', $event) }}" wire:navigate class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800 hover:bg-amber-100" data-signal-event="navigation.event_offline_admissions_opened" data-signal-category="navigation" data-signal-component="event_schedule" data-signal-control="offline_admissions" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}">{{ __('Offline admissions') }}</a>
                @endcan
                <button type="button" wire:click="openOccurrenceForm" class="rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 hover:bg-emerald-700" data-signal-event="event_occurrence_create_started" data-signal-category="event_management" data-signal-component="event_schedule" data-signal-control="add_occurrence">+ {{ __('Add occurrence') }}</button>
            </div>
        </header>

        <section class="rounded-3xl border border-emerald-100 bg-emerald-50/70 p-5 text-sm leading-6 text-emerald-950">
            <p class="font-semibold">{{ __('How the schedule is organized') }}</p>
            <p class="mt-1">{{ __('The event is the umbrella. An occurrence is one date or run of the event. A session is a talk, class, or activity inside that occurrence. Simple events can use one occurrence with no detailed sessions.') }}</p>
            <p class="mt-2 text-xs text-emerald-900/70">{{ __('Times are entered and shown in the selected timezone; stored schedule timestamps remain UTC.') }}</p>
        </section>

        @if($showOccurrenceForm)
            <section class="rounded-3xl border border-emerald-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="occurrence-form-title">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div><p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ $editingOccurrenceId ? __('Edit occurrence') : __('New occurrence') }}</p><h2 id="occurrence-form-title" class="mt-2 font-heading text-2xl font-bold text-[#0b2a42]">{{ $editingOccurrenceId ? __('Update this date') : __('Add another date') }}</h2></div>
                    <button type="button" wire:click="closeOccurrenceForm" class="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">{{ __('Cancel') }}</button>
                </div>
                <form wire:submit="saveOccurrence" data-signal-submit-event="{{ $editingOccurrenceId ? 'event_occurrence_update_submitted' : 'event_occurrence_create_submitted' }}" data-signal-category="event_management" data-signal-component="event_schedule" data-signal-control="occurrence_form" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="mt-6 grid gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2"><label for="occurrence-title" class="text-sm font-semibold text-slate-800">{{ __('Date / occurrence name') }}</label><input id="occurrence-title" type="text" wire:model="occurrenceForm.title" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100" placeholder="{{ __('For example: Day 2 or Saturday programme') }}">@error('occurrenceForm.title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <div><label for="occurrence-starts" class="text-sm font-semibold text-slate-800">{{ __('Starts') }}</label><input id="occurrence-starts" type="datetime-local" wire:model="occurrenceForm.starts_at" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100">@error('occurrenceForm.starts_at')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <div><label for="occurrence-ends" class="text-sm font-semibold text-slate-800">{{ __('Ends') }}</label><input id="occurrence-ends" type="datetime-local" wire:model="occurrenceForm.ends_at" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100">@error('occurrenceForm.ends_at')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <div><label for="occurrence-timezone" class="text-sm font-semibold text-slate-800">{{ __('Timezone') }}</label><input id="occurrence-timezone" type="text" wire:model="occurrenceForm.timezone" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100" placeholder="Asia/Kuala_Lumpur">@error('occurrenceForm.timezone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <div><label for="occurrence-capacity" class="text-sm font-semibold text-slate-800">{{ __('Capacity') }}</label><input id="occurrence-capacity" type="number" min="1" wire:model="occurrenceForm.capacity" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm" placeholder="{{ __('Optional') }}">@error('occurrenceForm.capacity')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <div class="flex justify-end sm:col-span-2"><button type="submit" class="rounded-xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-700" wire:loading.attr="disabled">{{ $editingOccurrenceId ? __('Save occurrence') : __('Add occurrence') }}</button></div>
                </form>
            </section>
        @endif

        @if($showSessionForm)
            <section class="rounded-3xl border border-indigo-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="session-form-title">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div><p class="text-xs font-bold uppercase tracking-[0.16em] text-indigo-700">{{ $editingSessionId ? __('Edit session') : __('New session') }}</p><h2 id="session-form-title" class="mt-2 font-heading text-2xl font-bold text-[#0b2a42]">{{ $editingSessionId ? __('Update this activity') : __('Add a session inside the occurrence') }}</h2></div>
                    <button type="button" wire:click="closeSessionForm" class="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">{{ __('Cancel') }}</button>
                </div>
                <form wire:submit="saveSession" data-signal-submit-event="{{ $editingSessionId ? 'event_session_update_submitted' : 'event_session_create_submitted' }}" data-signal-category="event_management" data-signal-component="event_schedule" data-signal-control="session_form" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="mt-6 grid gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2"><label for="session-title" class="text-sm font-semibold text-slate-800">{{ __('Session title') }}</label><input id="session-title" type="text" wire:model="sessionForm.title" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-indigo-400 focus:bg-white focus:ring-4 focus:ring-indigo-100" placeholder="{{ __('For example: Opening talk') }}">@error('sessionForm.title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <div><label for="session-starts" class="text-sm font-semibold text-slate-800">{{ __('Starts') }}</label><input id="session-starts" type="datetime-local" wire:model="sessionForm.starts_at" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-indigo-400 focus:bg-white focus:ring-4 focus:ring-indigo-100">@error('sessionForm.starts_at')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <div><label for="session-ends" class="text-sm font-semibold text-slate-800">{{ __('Ends') }}</label><input id="session-ends" type="datetime-local" wire:model="sessionForm.ends_at" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-indigo-400 focus:bg-white focus:ring-4 focus:ring-indigo-100">@error('sessionForm.ends_at')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <div><label for="session-timezone" class="text-sm font-semibold text-slate-800">{{ __('Timezone') }}</label><input id="session-timezone" type="text" wire:model="sessionForm.timezone" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-indigo-400 focus:bg-white focus:ring-4 focus:ring-indigo-100">@error('sessionForm.timezone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <div><label for="session-capacity" class="text-sm font-semibold text-slate-800">{{ __('Capacity') }}</label><input id="session-capacity" type="number" min="1" wire:model="sessionForm.capacity" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm" placeholder="{{ __('Optional') }}">@error('sessionForm.capacity')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <div><label for="session-summary" class="text-sm font-semibold text-slate-800">{{ __('Short summary') }}</label><input id="session-summary" type="text" wire:model="sessionForm.summary" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm">@error('sessionForm.summary')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <div class="sm:col-span-2"><label for="session-description" class="text-sm font-semibold text-slate-800">{{ __('Description') }}</label><textarea id="session-description" rows="4" wire:model="sessionForm.description" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-indigo-400 focus:bg-white focus:ring-4 focus:ring-indigo-100"></textarea>@error('sessionForm.description')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                    <div class="flex justify-end sm:col-span-2"><button type="submit" class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white hover:bg-indigo-700" wire:loading.attr="disabled">{{ $editingSessionId ? __('Save session') : __('Add session') }}</button></div>
                </form>
            </section>
        @endif

        <section class="space-y-5">
            @forelse($occurrences as $occurrence)
                @php($sessions = $occurrence->sessions->sortBy('starts_at'))
                <article wire:key="schedule-occurrence-{{ $occurrence->id }}" class="overflow-hidden rounded-3xl border border-[#eadfca] bg-white shadow-sm">
                    <div class="flex flex-col gap-5 border-b border-slate-100 p-6 sm:flex-row sm:items-start sm:justify-between sm:p-8">
                        <div class="flex gap-4">
                            <div class="flex size-14 shrink-0 flex-col items-center justify-center rounded-2xl bg-slate-950 text-white"><span class="text-[10px] font-bold uppercase tracking-[0.12em] text-amber-300">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($occurrence->starts_at, 'M') }}</span><span class="text-2xl font-bold leading-none">{{ \App\Support\Timezone\UserDateTimeFormatter::format($occurrence->starts_at, 'd') }}</span></div>
                            <div><div class="flex flex-wrap items-center gap-2"><h2 class="font-heading text-2xl font-bold text-[#0b2a42]">{{ $occurrence->title }}</h2><span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold uppercase tracking-[0.12em] text-slate-600">{{ $occurrence->status->getValue() }}</span></div><p class="mt-2 text-sm text-slate-600">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($occurrence->starts_at, 'l, j F Y') }} · {{ \App\Support\Timezone\UserDateTimeFormatter::format($occurrence->starts_at, 'h:i A') }} — {{ \App\Support\Timezone\UserDateTimeFormatter::format($occurrence->ends_at, 'h:i A') }}</p><p class="mt-1 text-xs text-slate-400">{{ $occurrence->timezone }}@if($occurrence->capacity !== null) · {{ __('Capacity') }}: {{ number_format($occurrence->capacity) }}@endif · {{ trans_choice(':count session|:count sessions', $sessions->count(), ['count' => $sessions->count()]) }}</p></div>
                        </div>
                        <div class="flex flex-wrap gap-2"><button type="button" wire:click="openSessionForm('{{ $occurrence->id }}')" class="rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-800 hover:bg-indigo-100" data-signal-event="event_session_create_started" data-signal-category="event_management" data-signal-component="event_schedule" data-signal-control="add_session">+ {{ __('Add session') }}</button><button type="button" wire:click="editOccurrence('{{ $occurrence->id }}')" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">{{ __('Edit') }}</button></div>
                    </div>
                    <div class="p-6 sm:p-8">
                        @forelse($sessions as $session)
                            <div wire:key="schedule-session-{{ $session->id }}" class="flex flex-col gap-3 border-b border-slate-100 py-4 first:pt-0 last:border-b-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between"><div><p class="text-xs font-bold uppercase tracking-[0.14em] text-indigo-700">{{ \App\Support\Timezone\UserDateTimeFormatter::format($session->starts_at, 'h:i A') }} — {{ \App\Support\Timezone\UserDateTimeFormatter::format($session->ends_at, 'h:i A') }}</p><h3 class="mt-1 font-semibold text-slate-900">{{ $session->title }}</h3>@if($session->summary)<p class="mt-1 text-sm text-slate-500">{{ $session->summary }}</p>@endif</div><button type="button" wire:click="editSession('{{ $session->id }}')" class="self-start rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 sm:self-auto">{{ __('Edit session') }}</button></div>
                        @empty
                            <div class="rounded-2xl bg-[#fbf8f1] p-5 text-sm leading-6 text-slate-600">{{ __('No detailed sessions yet. The occurrence can still be used as a simple one-date event, or you can add the activities inside it now.') }}</div>
                        @endforelse
                    </div>
                </article>
            @empty
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center"><p class="font-heading text-2xl font-bold text-[#0b2a42]">{{ __('No occurrences yet') }}</p><p class="mx-auto mt-2 max-w-lg text-sm leading-6 text-slate-500">{{ __('Add the first date to make the schedule visible to participants.') }}</p><button type="button" wire:click="openOccurrenceForm" class="mt-5 rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-700">+ {{ __('Add occurrence') }}</button></div>
            @endforelse
        </section>
    </div>
</main>
