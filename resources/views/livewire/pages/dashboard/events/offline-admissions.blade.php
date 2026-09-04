@section('title', __('Offline admissions') . ' · ' . $event->title . ' - ' . config('app.name'))

<main class="min-h-screen bg-[#fbf8f1] px-6 py-10 lg:px-12">
    <div class="mx-auto max-w-7xl space-y-8">
        <header class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <a href="{{ route('dashboard.events.schedule', $event) }}" wire:navigate class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">← {{ __('Schedule') }}</a>
                <p class="mt-5 text-xs font-bold uppercase tracking-[0.18em] text-amber-700">{{ __('Organizer desk') }}</p>
                <h1 class="mt-2 font-heading text-4xl font-bold tracking-tight text-[#0b2a42]">{{ __('Offline admissions') }}</h1>
                <p class="mt-2 max-w-3xl text-sm leading-7 text-slate-600">{{ __('Register someone at the door or record a sale made outside the website. The admission uses the same order, registration, inventory, pass, and attendance records as an online purchase.') }}</p>
            </div>
            <nav class="flex flex-wrap gap-2" aria-label="{{ __('Event tools') }}">
                <a href="{{ route('dashboard.events.participants', $event) }}" wire:navigate class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm font-semibold text-sky-800 hover:bg-sky-100" data-signal-event="navigation.event_participants_opened" data-signal-category="navigation" data-signal-component="offline_admissions" data-signal-control="participants" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}">{{ __('Participants') }}</a>
                <a href="{{ route('dashboard.events.registration-questions', $event) }}" wire:navigate class="rounded-xl border border-violet-200 bg-violet-50 px-4 py-3 text-sm font-semibold text-violet-800 hover:bg-violet-100" data-signal-event="navigation.event_registration_questions_opened" data-signal-category="navigation" data-signal-component="offline_admissions" data-signal-control="registration_questions" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}">{{ __('Questions') }}</a>
            </nav>
        </header>

        <section class="grid gap-4 md:grid-cols-3">
            <div class="rounded-3xl border border-amber-200 bg-amber-50/80 p-5 text-sm leading-6 text-amber-950 md:col-span-2">
                <p class="font-semibold">{{ __('A simple door-desk workflow') }}</p>
                <p class="mt-1">{{ __('Choose the admission, enter the participant, confirm that they accepted the event agreement, then record whether payment has been received. Pending admissions do not issue a pass until you confirm payment.') }}</p>
            </div>
            <div class="rounded-3xl border border-slate-200 bg-white p-5 text-sm leading-6 text-slate-600">
                <p class="font-semibold text-slate-900">{{ __('No seat assignment in v1') }}</p>
                <p class="mt-1">{{ __('This records admission and capacity only. It does not select or assign a seat.') }}</p>
            </div>
        </section>

        <div class="grid items-start gap-8 lg:grid-cols-[minmax(0,1.15fr)_minmax(300px,0.85fr)]">
            <section class="rounded-3xl border border-[#eadfca] bg-white p-6 shadow-sm sm:p-8" aria-labelledby="offline-admission-form-title">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('New admission') }}</p>
                        <h2 id="offline-admission-form-title" class="mt-2 font-heading text-2xl font-bold text-[#0b2a42]">{{ __('Add one participant') }}</h2>
                    </div>
                    @if($ticketOptions !== [])
                        <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-bold uppercase tracking-[0.12em] text-emerald-800">{{ __('Organizer only') }}</span>
                    @endif
                </div>

                @if($ticketOptions === [])
                    <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm leading-6 text-rose-900">
                        <p class="font-semibold">{{ __('No active admission types are available.') }}</p>
                        <p class="mt-1">{{ __('Create an active ticket type for this event before recording an offline admission.') }}</p>
                    </div>
                @else
                    <form wire:submit="submit" data-signal-submit-event="event.offline_admission_submitted" data-signal-category="event_operations" data-signal-component="offline_admissions" data-signal-control="admission_form" data-signal-entity-type="event" data-signal-entity-id="{{ $event->id }}" class="mt-6 space-y-7">
                        <div>
                            <label for="offline-ticket" class="text-sm font-semibold text-slate-800">{{ __('Admission type') }}</label>
                            <select id="offline-ticket" wire:model.live="ticketId" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-emerald-400 focus:bg-white focus:ring-4 focus:ring-emerald-100">
                                @foreach($ticketOptions as $ticketKey => $ticket)
                                    <option value="{{ $ticketKey }}">{{ $ticket['label'] }} · {{ $this->formatMoney($ticket['price'], $ticket['currency']) }} · {{ $ticket['scope'] }}</option>
                                @endforeach
                            </select>
                            @error('ticketId')<p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p>@enderror
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-[#fbf8f1] p-5">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ __('Participant') }}</p>
                                    <p class="mt-1 text-sm text-slate-600">{{ __('This is the person who will use the admission.') }}</p>
                                </div>
                                <span class="text-2xl" aria-hidden="true">◎</span>
                            </div>
                            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <label for="offline-name" class="text-sm font-semibold text-slate-800">{{ __('Full name') }}</label>
                                    <input id="offline-name" type="text" wire:model.blur="participant.name" autocomplete="name" required maxlength="150" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100" placeholder="{{ __('Participant name') }}">
                                    @error('participant.name')<p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label for="offline-email" class="text-sm font-semibold text-slate-800">{{ __('Email (optional)') }}</label>
                                    <input id="offline-email" type="email" wire:model.blur="participant.email" autocomplete="email" maxlength="255" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100" placeholder="{{ __('For confirmation messages') }}">
                                    @error('participant.email')<p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label for="offline-phone" class="text-sm font-semibold text-slate-800">{{ __('Phone (optional)') }}</label>
                                    <input id="offline-phone" type="tel" wire:model.blur="participant.phone" autocomplete="tel" maxlength="40" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100" placeholder="{{ __('Contact number') }}">
                                    @error('participant.phone')<p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p>@enderror
                                </div>
                                @if($participantIdentityType !== 'none')
                                    <div class="sm:col-span-2">
                                        <label for="offline-identity" class="text-sm font-semibold text-slate-800">{{ $participantIdentityLabel }}</label>
                                        <input id="offline-identity" type="text" wire:model.blur="participant.identity_document" autocomplete="off" required maxlength="100" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-white px-4 text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100" placeholder="{{ __('Enter only when the event requires it') }}">
                                        <p class="mt-1 text-xs text-slate-500">{{ __('Stored securely for this event and shown masked in the participant workspace.') }}</p>
                                        @error('participant.identity_document')<p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p>@enderror
                                    </div>
                                @endif
                                <label class="flex cursor-pointer items-start gap-3 sm:col-span-2">
                                    <input type="checkbox" wire:model="participant.is_purchaser" class="mt-0.5 size-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                    <span class="text-sm leading-6 text-slate-700"><span class="font-semibold text-slate-900">{{ __('The participant is also the purchaser') }}</span><span class="block text-xs text-slate-500">{{ __('Leave this off when you are recording an admission bought or sponsored by someone else.') }}</span></span>
                                </label>
                            </div>
                        </div>

                        @if($registrationQuestions->isNotEmpty())
                            <div class="space-y-5">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-violet-700">{{ __('Participant questions') }}</p>
                                    <p class="mt-1 text-sm text-slate-600">{{ __('Answer the same questions that appear in the normal registration flow.') }}</p>
                                </div>
                                @foreach($registrationQuestions as $question)
                                    @php($answerPath = 'participant.answers.' . $question->field_key)
                                    <div>
                                        <label for="offline-question-{{ $question->id }}" class="text-sm font-semibold text-slate-800">{{ $question->question }} @if($question->is_required)<span class="text-rose-600">*</span>@endif</label>
                                        @if($question->description)<p class="mt-1 text-xs leading-5 text-slate-500">{{ $question->description }}</p>@endif
                                        @if($question->type->value === 'textarea')
                                            <textarea id="offline-question-{{ $question->id }}" wire:model.blur="{{ $answerPath }}" rows="3" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-violet-400 focus:bg-white focus:ring-4 focus:ring-violet-100"></textarea>
                                        @elseif($question->type->value === 'select')
                                            <select id="offline-question-{{ $question->id }}" wire:model="{{ $answerPath }}" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-violet-400 focus:bg-white focus:ring-4 focus:ring-violet-100">
                                                <option value="">{{ __('Choose an answer') }}</option>
                                                @foreach($question->options ?? [] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                                            </select>
                                        @elseif($question->type->value === 'multiselect')
                                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                                @foreach($question->options ?? [] as $option)
                                                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700">
                                                        <input type="checkbox" wire:model="{{ $answerPath }}" value="{{ $option }}" class="size-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500">
                                                        <span>{{ $option }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        @elseif($question->type->value === 'checkbox')
                                            <label class="mt-2 flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-700">
                                                <input id="offline-question-{{ $question->id }}" type="checkbox" wire:model="{{ $answerPath }}" class="size-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500">
                                                <span>{{ __('Yes') }}</span>
                                            </label>
                                        @else
                                            <input id="offline-question-{{ $question->id }}" type="{{ $question->type->value === 'number' ? 'number' : ($question->type->value === 'date' ? 'date' : 'text') }}" wire:model.blur="{{ $answerPath }}" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-violet-400 focus:bg-white focus:ring-4 focus:ring-violet-100">
                                        @endif
                                        @error($answerPath)<p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p>@enderror
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <div class="rounded-2xl border border-emerald-200 bg-emerald-50/60 p-5">
                            <label class="flex cursor-pointer items-start gap-3">
                                <input type="checkbox" wire:model="agreementAccepted" required class="mt-1 size-4 rounded border-emerald-300 text-emerald-600 focus:ring-emerald-500">
                                <span class="text-sm leading-6 text-emerald-950"><span class="font-semibold">{{ __('I confirm the participant was informed of and accepted the event agreement.') }}</span><span class="block text-xs text-emerald-900/70">{{ __('This covers the participation waiver, code of conduct, and photo/video consent stated for this event. The record notes that the organizer recorded this confirmation.') }}</span></span>
                            </label>
                            @error('agreementAccepted')<p class="mt-2 text-xs font-semibold text-rose-700">{{ $message }}</p>@enderror
                        </div>

                        <div class="border-t border-slate-100 pt-6">
                            <div class="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="offline-payment-state" class="text-sm font-semibold text-slate-800">{{ __('Payment status') }}</label>
                                    <select id="offline-payment-state" wire:model.live="paymentState" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-amber-400 focus:bg-white focus:ring-4 focus:ring-amber-100">
                                        <option value="confirmed">{{ __('Payment received — issue pass now') }}</option>
                                        <option value="pending">{{ __('Not received yet — keep pending') }}</option>
                                    </select>
                                    @error('paymentState')<p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label for="offline-payment-method" class="text-sm font-semibold text-slate-800">{{ __('Payment method') }}</label>
                                    <select id="offline-payment-method" wire:model.live="paymentMethod" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-amber-400 focus:bg-white focus:ring-4 focus:ring-amber-100">
                                        @foreach($paymentMethods as $methodKey => $methodLabel)
                                            <option value="{{ $methodKey }}" @disabled($paymentState === 'pending' && $methodKey === 'complimentary')>{{ $methodLabel }}</option>
                                        @endforeach
                                    </select>
                                    @error('paymentMethod')<p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label for="offline-payment-reference" class="text-sm font-semibold text-slate-800">{{ __('Payment reference (optional)') }}</label>
                                    <input id="offline-payment-reference" type="text" wire:model.blur="paymentReference" maxlength="120" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-amber-400 focus:bg-white focus:ring-4 focus:ring-amber-100" placeholder="{{ __('Receipt no. or transfer reference') }}">
                                    @error('paymentReference')<p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label for="offline-notes" class="text-sm font-semibold text-slate-800">{{ __('Internal note (optional)') }}</label>
                                    <input id="offline-notes" type="text" wire:model.blur="notes" maxlength="1000" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-amber-400 focus:bg-white focus:ring-4 focus:ring-amber-100" placeholder="{{ __('For your organizer team') }}">
                                    @error('notes')<p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p>@enderror
                                </div>
                            </div>
                            @if($paymentState === 'pending')
                                <p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-900">{{ __('The participant will be recorded, but no pass will be issued and the inventory will not be consumed until payment is confirmed below.') }}</p>
                            @endif
                        </div>

                        <div class="flex items-center justify-end gap-3">
                            <button type="submit" wire:loading.attr="disabled" wire:target="submit" class="rounded-xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 hover:bg-emerald-700 disabled:cursor-wait disabled:opacity-60" data-signal-event="event.offline_admission_create_requested" data-signal-category="event_operations" data-signal-component="offline_admissions" data-signal-control="create_admission">{{ __('Record admission') }}</button>
                        </div>
                    </form>
                @endif
            </section>

            <aside class="space-y-5 lg:sticky lg:top-6">
                <section class="overflow-hidden rounded-3xl border border-slate-900 bg-slate-950 text-white shadow-xl" aria-labelledby="desk-card-title">
                    <div class="border-b border-white/10 px-6 py-5">
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-300">{{ __('Admission slip') }}</p>
                        <h2 id="desk-card-title" class="mt-2 font-heading text-2xl font-bold">{{ __('Ready at the desk') }}</h2>
                    </div>
                    <div class="space-y-5 px-6 py-6">
                        <div>
                            <p class="text-xs uppercase tracking-[0.14em] text-slate-400">{{ __('Selected admission') }}</p>
                            @if(isset($ticketOptions[$ticketId]))
                                <p class="mt-1 text-lg font-semibold">{{ $ticketOptions[$ticketId]['label'] }}</p>
                                <p class="mt-1 text-sm text-slate-300">{{ $ticketOptions[$ticketId]['scope'] }}</p>
                                <p class="mt-3 text-3xl font-bold text-amber-300">{{ $this->formatMoney($ticketOptions[$ticketId]['price'], $ticketOptions[$ticketId]['currency']) }}</p>
                            @else
                                <p class="mt-1 text-sm text-slate-300">{{ __('Choose an active admission type.') }}</p>
                            @endif
                        </div>
                        <div class="grid grid-cols-2 gap-3 border-t border-white/10 pt-5 text-sm">
                            <div><p class="text-slate-400">{{ __('Seat') }}</p><p class="mt-1 font-semibold text-white">{{ __('Not assigned') }}</p></div>
                            <div><p class="text-slate-400">{{ __('Pass') }}</p><p class="mt-1 font-semibold text-white">{{ $paymentState === 'pending' ? __('After payment') : __('Issued now') }}</p></div>
                        </div>
                    </div>
                </section>

                @if($lastAdmission)
                    <section class="rounded-3xl border border-emerald-200 bg-emerald-50 p-6" aria-labelledby="last-admission-title">
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-emerald-700">{{ __('Last recorded') }}</p>
                        <h2 id="last-admission-title" class="mt-2 font-heading text-xl font-bold text-emerald-950">{{ $lastAdmission['name'] }}</h2>
                        <p class="mt-1 text-sm text-emerald-900/80">{{ $lastAdmission['registration_no'] }} · {{ $lastAdmission['order_number'] }}</p>
                        <div class="mt-4 flex flex-wrap gap-2 text-xs font-bold">
                            <span class="rounded-full bg-white px-3 py-1.5 text-emerald-800">{{ $this->statusLabel($lastAdmission['status']) }}</span>
                            <span class="rounded-full bg-white px-3 py-1.5 text-emerald-800">{{ $this->paymentStatusLabel($lastAdmission['payment_status']) }}</span>
                            <span class="rounded-full bg-white px-3 py-1.5 text-emerald-800">{{ trans_choice(':count pass|:count passes', $lastAdmission['pass_count'], ['count' => $lastAdmission['pass_count']]) }}</span>
                        </div>
                    </section>
                @endif

                <section class="rounded-3xl border border-[#eadfca] bg-white p-6 text-sm leading-6 text-slate-600">
                    <p class="font-semibold text-slate-900">{{ __('What gets recorded') }}</p>
                    <ul class="mt-3 space-y-2">
                        <li>✓ {{ __('participant and event-scoped answers') }}</li>
                        <li>✓ {{ __('agreement confirmation and staff member') }}</li>
                        <li>✓ {{ __('order and payment state') }}</li>
                        <li>✓ {{ __('capacity, inventory, and admission pass') }}</li>
                    </ul>
                </section>
            </aside>
        </div>

        @if($confirmingOrderId)
            <section class="rounded-3xl border border-amber-200 bg-amber-50 p-6 sm:p-8" aria-labelledby="confirm-admission-title">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">{{ __('Pending admission') }}</p>
                        <h2 id="confirm-admission-title" class="mt-2 font-heading text-2xl font-bold text-amber-950">{{ __('Confirm payment and issue the pass') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-amber-900/80">{{ __('This does not change the participant details. It only confirms the offline payment and completes the admission.') }}</p>
                    </div>
                    <button type="button" wire:click="cancelConfirmation" class="rounded-xl border border-amber-300 bg-white px-3 py-2 text-sm font-semibold text-amber-900 hover:bg-amber-100">{{ __('Cancel') }}</button>
                </div>
                <div class="mt-6 grid gap-5 sm:grid-cols-3">
                    <div>
                        <label for="confirmation-payment-method" class="text-sm font-semibold text-amber-950">{{ __('Payment method') }}</label>
                        <select id="confirmation-payment-method" wire:model="confirmationPaymentMethod" class="mt-2 h-12 w-full rounded-2xl border border-amber-200 bg-white px-4 text-sm outline-none focus:border-amber-500 focus:ring-4 focus:ring-amber-100">
                            <option value="cash">{{ __('Cash') }}</option>
                            <option value="bank_transfer">{{ __('Bank transfer') }}</option>
                        </select>
                        @error('confirmationPaymentMethod')<p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="confirmation-payment-reference" class="text-sm font-semibold text-amber-950">{{ __('Payment reference (optional)') }}</label>
                        <input id="confirmation-payment-reference" type="text" wire:model.blur="confirmationPaymentReference" maxlength="120" class="mt-2 h-12 w-full rounded-2xl border border-amber-200 bg-white px-4 text-sm outline-none focus:border-amber-500 focus:ring-4 focus:ring-amber-100" placeholder="{{ __('Receipt no. or transfer reference') }}">
                        @error('confirmationPaymentReference')<p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="confirmation-notes" class="text-sm font-semibold text-amber-950">{{ __('Note (optional)') }}</label>
                        <input id="confirmation-notes" type="text" wire:model.blur="confirmationNotes" maxlength="1000" class="mt-2 h-12 w-full rounded-2xl border border-amber-200 bg-white px-4 text-sm outline-none focus:border-amber-500 focus:ring-4 focus:ring-amber-100" placeholder="{{ __('For your organizer team') }}">
                        @error('confirmationNotes')<p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="mt-5 flex justify-end">
                    <button type="button" wire:click="confirmPendingAdmission" wire:loading.attr="disabled" wire:target="confirmPendingAdmission" class="rounded-xl bg-amber-600 px-5 py-3 text-sm font-semibold text-white hover:bg-amber-700 disabled:cursor-wait disabled:opacity-60" data-signal-event="event.offline_admission_payment_confirmed" data-signal-category="event_operations" data-signal-component="offline_admissions" data-signal-control="confirm_pending_admission">{{ __('Confirm payment and issue pass') }}</button>
                </div>
            </section>
        @endif

        <section class="overflow-hidden rounded-3xl border border-[#eadfca] bg-white shadow-sm" aria-labelledby="recent-admissions-title">
            <div class="border-b border-slate-100 px-5 py-5 sm:px-6">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 id="recent-admissions-title" class="font-heading text-2xl font-bold text-[#0b2a42]">{{ __('Recent offline admissions') }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ __('Pending rows remain here until payment is confirmed. The participant list contains the complete record.') }}</p>
                    </div>
                    <a href="{{ route('dashboard.events.participants', $event) }}" wire:navigate class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">{{ __('Open participant list') }} →</a>
                </div>
            </div>
            @if($recentAdmissions->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-[0.12em] text-slate-500">
                            <tr>
                                <th class="px-5 py-3 font-bold sm:px-6">{{ __('Participant') }}</th>
                                <th class="px-5 py-3 font-bold sm:px-6">{{ __('Admission') }}</th>
                                <th class="px-5 py-3 font-bold sm:px-6">{{ __('Status') }}</th>
                                <th class="px-5 py-3 font-bold sm:px-6">{{ __('Recorded') }}</th>
                                <th class="px-5 py-3 text-right font-bold sm:px-6">{{ __('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($recentAdmissions as $admission)
                                <tr wire:key="offline-admission-{{ $admission['id'] }}" class="align-top">
                                    <td class="px-5 py-4 sm:px-6"><p class="font-semibold text-slate-900">{{ $admission['name'] }}</p><p class="mt-1 text-xs text-slate-500">{{ $admission['registration_no'] }} · {{ $admission['order_number'] }}</p></td>
                                    <td class="px-5 py-4 text-slate-700 sm:px-6">{{ $admission['ticket'] }}</td>
                                    <td class="px-5 py-4 sm:px-6"><div class="flex flex-wrap gap-1.5"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">{{ $this->statusLabel($admission['status']) }}</span><span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-bold text-amber-800">{{ $this->paymentStatusLabel($admission['payment_status']) }}</span></div></td>
                                    <td class="px-5 py-4 text-xs text-slate-500 sm:px-6">{{ $admission['registered_at'] }}</td>
                                    <td class="px-5 py-4 text-right sm:px-6">
                                        @if($admission['status'] === 'pending' && $admission['order_id'])
                                            <button type="button" wire:click="startConfirmation('{{ $admission['order_id'] }}')" class="rounded-xl border border-amber-300 px-3 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-50" data-signal-event="event.offline_admission_confirmation_started" data-signal-category="event_operations" data-signal-component="offline_admissions" data-signal-control="start_confirmation" data-signal-entity-type="event_registration" data-signal-entity-id="{{ $admission['id'] }}">{{ __('Confirm payment') }}</button>
                                        @else
                                            <span class="text-xs font-semibold text-emerald-700">{{ trans_choice(':count pass|:count passes', $admission['pass_count'], ['count' => $admission['pass_count']]) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="px-6 py-14 text-center">
                    <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-amber-50 text-2xl text-amber-700">◎</div>
                    <h3 class="mt-5 font-heading text-2xl font-bold text-[#0b2a42]">{{ __('No offline admissions yet') }}</h3>
                    <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">{{ __('The first participant you record at the desk will appear here.') }}</p>
                </div>
            @endif
        </section>
    </div>
</main>
