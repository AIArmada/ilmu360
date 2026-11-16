@section('title', __('Registration questions') . ' · ' . $event->title . ' - ' . config('app.name'))

<main class="min-h-screen bg-[#fbf8f1] px-6 py-10 lg:px-12">
    <div class="mx-auto max-w-6xl space-y-8">
        <header class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <a href="{{ route('dashboard.events.schedule', $event) }}" wire:navigate class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">← {{ __('Schedule') }}</a>
                <p class="mt-5 text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">{{ __('Registration') }}</p>
                <h1 class="mt-2 font-heading text-4xl font-bold tracking-tight text-[#0b2a42]">{{ __('Questions for participants') }}</h1>
                <p class="mt-2 max-w-3xl text-sm leading-7 text-slate-600">{{ __('Ask for the extra information you genuinely need. Each answer is saved against the individual participant, not only the person who paid.') }}</p>
            </div>
            <button type="button" wire:click="openQuestionForm" data-signal-event="event.registration_question_create_started" data-signal-category="event_management" data-signal-component="registration_questions" data-signal-control="add_question" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 hover:bg-emerald-700">+ {{ __('Add question') }}</button>
        </header>

        <section class="grid gap-4 md:grid-cols-3">
            <div class="rounded-3xl border border-emerald-100 bg-emerald-50/70 p-5 text-sm leading-6 text-emerald-950 md:col-span-2">
                <p class="font-semibold">{{ __('How this works') }}</p>
                <p class="mt-1">{{ __('Questions are shown while the buyer lists each participant. The buyer must answer required questions for every participant. Answers keep the question wording used at registration, so later edits do not rewrite history.') }}</p>
            </div>
            <div class="rounded-3xl border border-slate-200 bg-white p-5 text-sm leading-6 text-slate-600">
                <p class="font-semibold text-slate-900">{{ __('Scope is flexible') }}</p>
                <p class="mt-1">{{ __('Use an event question for everyone, or ask something only for one date or session.') }}</p>
            </div>
        </section>

        @if($showForm)
            <section class="rounded-3xl border border-emerald-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="question-form-title">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ $editingQuestionId ? __('Edit question') : __('New question') }}</p>
                        <h2 id="question-form-title" class="mt-2 font-heading text-2xl font-bold text-[#0b2a42]">{{ $editingQuestionId ? __('Update participant question') : __('What do you need to ask?') }}</h2>
                    </div>
                    <button type="button" wire:click="closeQuestionForm" class="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">{{ __('Cancel') }}</button>
                </div>

                <form wire:submit="saveQuestion" data-signal-submit-event="{{ $editingQuestionId ? 'event.registration_question_update_submitted' : 'event.registration_question_create_submitted' }}" data-signal-category="event_management" data-signal-component="registration_questions" data-signal-control="question_form" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="mt-6 grid gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="question-text" class="text-sm font-semibold text-slate-800">{{ __('Question') }}</label>
                        <input id="question-text" type="text" wire:model="questionForm.question" maxlength="500" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100" placeholder="{{ __('For example: Do you have any dietary requirements?') }}">
                        @error('questionForm.question')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="question-scope" class="text-sm font-semibold text-slate-800">{{ __('Ask this for') }}</label>
                        <select id="question-scope" wire:model.live="questionForm.scope" @disabled($editingQuestionId !== null) class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100">
                            @foreach($scopeOptions as $scopeKey => $scopeLabel)
                                <option value="{{ $scopeKey }}">{{ $scopeLabel }}</option>
                            @endforeach
                        </select>
                        @if($editingQuestionId)<p class="mt-1 text-xs text-slate-500">{{ __('The scope stays fixed after answers may have been recorded.') }}</p>@endif
                        @error('questionForm.scope')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        @if(($questionForm['scope'] ?? 'event') !== 'event')
                            <label for="question-occurrence" class="text-sm font-semibold text-slate-800">{{ __('Occurrence / date') }}</label>
                            <select id="question-occurrence" wire:model.live="questionForm.occurrence_id" @disabled($editingQuestionId !== null && ($questionForm['scope'] ?? '') !== 'event') class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100">
                                <option value="">{{ __('Choose a date') }}</option>
                                @foreach($occurrenceOptions as $occurrenceKey => $occurrenceLabel)
                                    <option value="{{ $occurrenceKey }}">{{ $occurrenceLabel }}</option>
                                @endforeach
                            </select>
                            @error('questionForm.occurrence_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        @else
                            <div class="h-full rounded-2xl bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-600">{{ __('This question applies to the whole event, including every occurrence and session.') }}</div>
                        @endif
                    </div>
                    @if(($questionForm['scope'] ?? 'event') === 'session')
                        <div class="sm:col-span-2">
                            <label for="question-session" class="text-sm font-semibold text-slate-800">{{ __('Session') }}</label>
                            <select id="question-session" wire:model="questionForm.session_id" @disabled($editingQuestionId !== null) class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100">
                                <option value="">{{ __('Choose a session') }}</option>
                                @foreach($sessionOptions as $sessionKey => $sessionLabel)
                                    <option value="{{ $sessionKey }}">{{ $sessionLabel }}</option>
                                @endforeach
                            </select>
                            @error('questionForm.session_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                    @endif
                    <div>
                        <label for="question-type" class="text-sm font-semibold text-slate-800">{{ __('Answer type') }}</label>
                        <select id="question-type" wire:model.live="questionForm.type" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100">
                            @foreach($questionTypes as $typeKey => $typeLabel)
                                <option value="{{ $typeKey }}">{{ $typeLabel }}</option>
                            @endforeach
                        </select>
                        @error('questionForm.type')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="flex cursor-pointer items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700">
                            <input type="checkbox" wire:model="questionForm.is_required" class="size-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                            <span>{{ __('Require an answer') }}</span>
                        </label>
                    </div>
                    @if(in_array(($questionForm['type'] ?? 'text'), ['select', 'multiselect'], true))
                        <div class="sm:col-span-2">
                            <label for="question-options" class="text-sm font-semibold text-slate-800">{{ __('Options') }}</label>
                            <textarea id="question-options" wire:model="questionForm.options" rows="4" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100" placeholder="{{ __('One option per line') }}"></textarea>
                            <p class="mt-1 text-xs text-slate-500">{{ __('Put one choice on each line. Repeated choices are automatically removed.') }}</p>
                            @error('questionForm.options')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                    @endif
                    <div class="sm:col-span-2">
                        <label for="question-description" class="text-sm font-semibold text-slate-800">{{ __('Helpful explanation (optional)') }}</label>
                        <textarea id="question-description" wire:model="questionForm.description" rows="3" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100" placeholder="{{ __('Tell participants why you need this information.') }}"></textarea>
                        @error('questionForm.description')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex justify-end sm:col-span-2">
                        <button type="submit" class="rounded-xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-700">{{ $editingQuestionId ? __('Save changes') : __('Add question') }}</button>
                    </div>
                </form>
            </section>
        @endif

        <section class="overflow-hidden rounded-3xl border border-[#eadfca] bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-5 sm:px-6">
                <h2 class="font-heading text-2xl font-bold text-[#0b2a42]">{{ __('Your questions') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('Archived questions stop appearing in new checkouts but remain available for historical records.') }}</p>
            </div>
            @if($questions->isNotEmpty())
                <div class="divide-y divide-slate-100">
                    @foreach($questions as $question)
                        <article wire:key="registration-question-{{ $question->id }}" class="flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-6 {{ $question->status->value === 'archived' ? 'bg-slate-50/70' : '' }}">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-semibold text-slate-900">{{ $question->question }}</h3>
                                    <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-[11px] font-bold uppercase tracking-[0.12em] text-indigo-700">{{ $question->type->label() }}</span>
                                    @if($question->is_required)<span class="rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-bold uppercase tracking-[0.12em] text-amber-700">{{ __('Required') }}</span>@endif
                                    @if($question->status->value === 'archived')<span class="rounded-full bg-slate-200 px-2.5 py-1 text-[11px] font-bold uppercase tracking-[0.12em] text-slate-600">{{ __('Archived') }}</span>@endif
                                </div>
                                @if($question->description)<p class="mt-2 text-sm leading-6 text-slate-600">{{ $question->description }}</p>@endif
                                @if($question->options)<p class="mt-2 text-xs text-slate-500">{{ __('Options') }}: {{ implode(' · ', $question->options) }}</p>@endif
                                <p class="mt-2 text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                                    @if($question->event_session_id){{ __('One session') }}@elseif($question->event_occurrence_id){{ __('One occurrence / date') }}@else{{ __('Entire event') }}@endif
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-wrap gap-2">
                                @if($question->status->value !== 'archived')
                                    <button type="button" wire:click="editQuestion('{{ $question->id }}')" data-signal-event="event.registration_question_edit_started" data-signal-category="event_management" data-signal-component="registration_questions" data-signal-control="edit_question" data-signal-entity-type="registration_question" data-signal-entity-id="{{ $question->id }}" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">{{ __('Edit') }}</button>
                                    <button type="button" wire:click="archiveQuestion('{{ $question->id }}')" data-signal-event="event.registration_question_archive_requested" data-signal-category="event_management" data-signal-component="registration_questions" data-signal-control="archive_question" data-signal-entity-type="registration_question" data-signal-entity-id="{{ $question->id }}" class="rounded-xl border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50">{{ __('Archive') }}</button>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="px-6 py-16 text-center">
                    <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-emerald-50 text-2xl text-emerald-700">?</div>
                    <h3 class="mt-5 font-heading text-2xl font-bold text-[#0b2a42]">{{ __('No extra questions yet') }}</h3>
                    <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">{{ __('Start with only the information your organizer team will actually use.') }}</p>
                </div>
            @endif
        </section>
    </div>
</main>
