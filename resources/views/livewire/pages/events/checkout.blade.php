@section('title', __('Event checkout') . ' - ' . config('app.name'))
@section('meta_robots', 'noindex, nofollow')

<main class="mx-auto max-w-6xl px-5 py-10 sm:px-8 lg:px-12">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('events.show', $event) }}" wire:navigate
            class="inline-flex items-center gap-2 text-sm font-bold text-[#173c34] hover:text-[#b27b1b]">
            <span aria-hidden="true">←</span> {{ __('Back to event') }}
        </a>

        <div class="mt-6 rounded-3xl border border-[#173c34]/10 bg-white p-6 shadow-xl shadow-[#173c34]/5 sm:p-8">
            <div class="flex flex-col gap-5 border-b border-slate-100 pb-6 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('Registration') }}</p>
                    <h1 class="mt-2 font-heading text-3xl font-semibold tracking-tight text-[#173c34]">{{ __('Review your registration') }}</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Create or use your verified ilmu360° account first. Your account keeps your admissions and receipt together.') }}</p>
                </div>
                <span class="inline-flex shrink-0 items-center rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-800">
                    {{ __('Account required') }}
                </span>
            </div>

            <div class="mt-6 rounded-2xl bg-[#f6f8f5] p-5">
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">{{ __('Event') }}</p>
                <h2 class="mt-1 text-xl font-bold text-[#173c34]">{{ $event->title }}</h2>
                @if ($ticket instanceof \AIArmada\Ticketing\Models\TicketType)
                    <div class="mt-3 flex flex-wrap items-center gap-2 text-sm text-slate-600">
                        <span class="rounded-full bg-white px-3 py-1 font-semibold shadow-sm">{{ $ticket->name }}</span>
                        @if ($ticket->ticketable && $ticket->ticketable->getKey() !== $event->getKey())
                            <span>· {{ $ticket->ticketable->title ?? __('Selected programme segment') }}</span>
                        @endif
                    </div>
                @else
                    <p class="mt-2 text-sm text-slate-600">{{ __('Free registration') }}</p>
                @endif
            </div>

            @if ($errors->has('checkout'))
                <div role="alert" class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold leading-6 text-rose-800">
                    {{ $errors->first('checkout') }}
                </div>
            @endif

            <form wire:submit="submit" class="mt-8 space-y-8"
                data-signal-submit-event="commerce.event_checkout_submitted"
                data-signal-category="commerce"
                data-signal-component="event_checkout"
                data-signal-control="checkout_form"
                data-signal-entity-type="event"
                data-signal-entity-id="{{ $event->getKey() }}"
                data-signal-props='@json(['ticket_type_id' => $ticket?->getKey(), 'direct_free_registration' => $isDirectFreeRegistration])'>
                <section aria-labelledby="participants-heading">
                    <div class="flex items-end justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-[#b27b1b]">{{ __('Who is attending?') }}</p>
                            <h2 id="participants-heading" class="mt-1 text-xl font-bold text-[#173c34]">{{ __('Participant details') }}</h2>
                        </div>
                        <span class="text-sm font-semibold text-slate-500">{{ trans_choice(':count participant|:count participants', $quantity, ['count' => $quantity]) }}</span>
                    </div>

                    <div class="mt-5 space-y-4">
                        @foreach ($participants as $index => $participant)
                            <div wire:key="participant-{{ $index }}" class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 sm:p-5">
                                <div class="flex items-center justify-between gap-3">
                                    <h3 class="font-bold text-[#173c34]">{{ __('Participant :number', ['number' => $index + 1]) }}</h3>
                                    @if ($quantity > $minimumQuantity)
                                        <button type="button" wire:click="removeParticipant" class="text-xs font-bold text-rose-700 hover:text-rose-900">
                                            {{ __('Remove') }}
                                        </button>
                                    @endif
                                </div>

                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    <div class="sm:col-span-2">
                                        <label for="participant-{{ $index }}-name" class="block text-sm font-bold text-slate-700">{{ __('Full name') }} <span class="text-rose-500">*</span></label>
                                        <input id="participant-{{ $index }}-name" type="text" wire:model.blur="participants.{{ $index }}.name" autocomplete="name" required
                                            class="mt-2 h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-slate-900 outline-none transition focus:border-[#173c34] focus:ring-4 focus:ring-[#173c34]/10">
                                        @error('participants.' . $index . '.name') <p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label for="participant-{{ $index }}-email" class="block text-sm font-bold text-slate-700">{{ __('Email') }}</label>
                                        <input id="participant-{{ $index }}-email" type="email" wire:model.blur="participants.{{ $index }}.email" autocomplete="email"
                                            class="mt-2 h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-slate-900 outline-none transition focus:border-[#173c34] focus:ring-4 focus:ring-[#173c34]/10">
                                        @error('participants.' . $index . '.email') <p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label for="participant-{{ $index }}-phone" class="block text-sm font-bold text-slate-700">{{ __('Phone') }}</label>
                                        <input id="participant-{{ $index }}-phone" type="tel" wire:model.blur="participants.{{ $index }}.phone" autocomplete="tel"
                                            class="mt-2 h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-slate-900 outline-none transition focus:border-[#173c34] focus:ring-4 focus:ring-[#173c34]/10">
                                        @error('participants.' . $index . '.phone') <p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p> @enderror
                                    </div>
                                    @if ($participantIdentityType !== 'none')
                                        <div>
                                            <label for="participant-{{ $index }}-identity" class="block text-sm font-bold text-slate-700">{{ $participantIdentityLabel }} <span class="text-rose-500">*</span></label>
                                            <input id="participant-{{ $index }}-identity" type="text" wire:model.blur="participants.{{ $index }}.identity_document" autocomplete="off" required
                                                class="mt-2 h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-slate-900 outline-none transition focus:border-[#173c34] focus:ring-4 focus:ring-[#173c34]/10">
                                            @error('participants.' . $index . '.identity_document') <p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p> @enderror
                                        </div>
                                    @endif
                                </div>

                                @if ($registrationQuestions->isNotEmpty())
                                    <div class="mt-5 border-t border-slate-200 pt-5">
                                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-[#b27b1b]">{{ __('Additional details') }}</p>
                                        <p class="mt-1 text-sm text-slate-500">{{ __('Please answer these questions for this participant.') }}</p>
                                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                            @foreach ($registrationQuestions as $question)
                                                @php
                                                    $questionPath = 'participants.' . $index . '.answers.' . $question->field_key;
                                                    $questionId = 'participant-' . $index . '-question-' . $question->field_key;
                                                    $questionRequired = $question->is_required;
                                                @endphp
                                                <div wire:key="participant-question-{{ $index }}-{{ $question->id }}" class="{{ $question->type->value === 'textarea' ? 'sm:col-span-2' : '' }}">
                                                    @if ($question->type->value === 'checkbox')
                                                        <label for="{{ $questionId }}" class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-white p-3 text-sm font-semibold text-slate-700">
                                                            <input id="{{ $questionId }}" type="checkbox" wire:model.live="{{ $questionPath }}" value="1" @required($questionRequired)
                                                                class="mt-0.5 size-4 rounded border-slate-300 text-[#173c34] focus:ring-[#173c34]">
                                                            <span>{{ $question->question }} @if($questionRequired)<span class="text-rose-500">*</span>@endif</span>
                                                        </label>
                                                    @elseif ($question->type->value === 'textarea')
                                                        <label for="{{ $questionId }}" class="block text-sm font-bold text-slate-700">{{ $question->question }} @if($questionRequired)<span class="text-rose-500">*</span>@endif</label>
                                                        @if($question->description)<p class="mt-1 text-xs leading-5 text-slate-500">{{ $question->description }}</p>@endif
                                                        <textarea id="{{ $questionId }}" wire:model.blur="{{ $questionPath }}" rows="3" @required($questionRequired)
                                                            class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-slate-900 outline-none transition focus:border-[#173c34] focus:ring-4 focus:ring-[#173c34]/10"></textarea>
                                                    @elseif ($question->type->value === 'select')
                                                        <label for="{{ $questionId }}" class="block text-sm font-bold text-slate-700">{{ $question->question }} @if($questionRequired)<span class="text-rose-500">*</span>@endif</label>
                                                        @if($question->description)<p class="mt-1 text-xs leading-5 text-slate-500">{{ $question->description }}</p>@endif
                                                        <select id="{{ $questionId }}" wire:model.live="{{ $questionPath }}" @required($questionRequired)
                                                            class="mt-2 h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-slate-900 outline-none transition focus:border-[#173c34] focus:ring-4 focus:ring-[#173c34]/10">
                                                            <option value="">{{ __('Select an option') }}</option>
                                                            @foreach (($question->options ?? []) as $option)
                                                                <option value="{{ $option }}">{{ $option }}</option>
                                                            @endforeach
                                                        </select>
                                                    @elseif ($question->type->value === 'multiselect')
                                                        <label for="{{ $questionId }}" class="block text-sm font-bold text-slate-700">{{ $question->question }} @if($questionRequired)<span class="text-rose-500">*</span>@endif</label>
                                                        @if($question->description)<p class="mt-1 text-xs leading-5 text-slate-500">{{ $question->description }}</p>@endif
                                                        <select id="{{ $questionId }}" wire:model.live="{{ $questionPath }}" multiple @required($questionRequired)
                                                            class="mt-2 min-h-28 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-slate-900 outline-none transition focus:border-[#173c34] focus:ring-4 focus:ring-[#173c34]/10">
                                                            @foreach (($question->options ?? []) as $option)
                                                                <option value="{{ $option }}">{{ $option }}</option>
                                                            @endforeach
                                                        </select>
                                                    @else
                                                        <label for="{{ $questionId }}" class="block text-sm font-bold text-slate-700">{{ $question->question }} @if($questionRequired)<span class="text-rose-500">*</span>@endif</label>
                                                        @if($question->description)<p class="mt-1 text-xs leading-5 text-slate-500">{{ $question->description }}</p>@endif
                                                        <input id="{{ $questionId }}" type="{{ $question->type->value === 'number' ? 'number' : ($question->type->value === 'date' ? 'date' : 'text') }}" wire:model.blur="{{ $questionPath }}" @required($questionRequired)
                                                            class="mt-2 h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-slate-900 outline-none transition focus:border-[#173c34] focus:ring-4 focus:ring-[#173c34]/10">
                                                    @endif
                                                    @error($questionPath) <p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p> @enderror
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                <label class="mt-4 flex cursor-pointer items-start gap-3 text-sm text-slate-700">
                                    <input type="checkbox" wire:model.live="participants.{{ $index }}.is_purchaser"
                                        class="mt-0.5 size-4 rounded border-slate-300 text-[#173c34] focus:ring-[#173c34]">
                                    <span><strong>{{ __('This is the purchaser') }}</strong><br><span class="text-xs text-slate-500">{{ __('The purchaser account owns the order and can manage cancellations or refunds if that policy is enabled.') }}</span></span>
                                </label>
                            </div>
                        @endforeach
                    </div>

                    @if ($quantity < $maximumQuantity)
                        <button type="button" wire:click="addParticipant" class="mt-4 inline-flex items-center gap-2 rounded-xl border border-dashed border-[#173c34]/30 px-4 py-3 text-sm font-bold text-[#173c34] transition hover:border-[#173c34] hover:bg-[#f6f8f5]">
                            <span aria-hidden="true">＋</span> {{ __('Add another participant') }}
                        </button>
                    @endif
                </section>

                @if (! $isDirectFreeRegistration)
                    <section aria-labelledby="discount-heading" class="border-t border-slate-100 pt-8">
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-[#b27b1b]">{{ __('Optional') }}</p>
                        <h2 id="discount-heading" class="mt-1 text-xl font-bold text-[#173c34]">{{ __('Discount code') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Enter a voucher or promotion code if the organizer provided one.') }}</p>
                        <input type="text" wire:model.blur="discountCode" autocomplete="off" placeholder="{{ __('Voucher or promotion code') }}"
                            class="mt-4 h-12 w-full rounded-xl border border-slate-200 bg-white px-4 uppercase tracking-wide text-slate-900 outline-none transition focus:border-[#173c34] focus:ring-4 focus:ring-[#173c34]/10">
                        @error('discountCode') <p class="mt-1 text-xs font-semibold text-rose-700">{{ $message }}</p> @enderror
                    </section>
                @endif

                <section aria-labelledby="agreement-heading" class="border-t border-slate-100 pt-8">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-[#b27b1b]">{{ __('Before you continue') }}</p>
                    <h2 id="agreement-heading" class="mt-1 text-xl font-bold text-[#173c34]">{{ __('Event agreement') }}</h2>
                    <label class="mt-4 flex cursor-pointer items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-700">
                        <input type="checkbox" wire:model.live="agreementAccepted" required
                            class="mt-1 size-4 rounded border-slate-300 text-[#173c34] focus:ring-[#173c34]">
                        <span>{{ __('I confirm that I have read and agree to the participation terms, code of conduct, and photo/video consent for every participant listed above.') }}</span>
                    </label>
                    @error('agreementAccepted') <p class="mt-1 text-xs font-semibold text-rose-700">{{ __('You must accept the event agreement before continuing.') }}</p> @enderror
                </section>

                <section class="border-t border-slate-100 pt-8">
                    <div class="rounded-2xl bg-[#173c34] p-5 text-white sm:p-6">
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-sm text-white/70">{{ __('Estimated total') }}</span>
                            <strong class="text-2xl">{{ \AIArmada\CommerceSupport\Support\MoneyFormatter::formatMinor($ticketPrice * $quantity, $ticket?->currency ?: config('checkout.defaults.currency', 'MYR')) }}</strong>
                        </div>
                        <p class="mt-2 text-xs leading-5 text-white/65">{{ $isDirectFreeRegistration ? __('No payment is required for this registration.') : __('The final total, including any valid discount, will be confirmed before payment.') }}</p>
                    </div>

                    <button type="submit" wire:loading.attr="disabled" wire:target="submit"
                        data-signal-event="commerce.event_checkout_continue_clicked"
                        data-signal-category="commerce"
                        data-signal-component="event_checkout"
                        data-signal-control="continue"
                        class="mt-5 inline-flex h-13 w-full items-center justify-center rounded-xl bg-[#b27b1b] px-5 py-3.5 font-bold text-white shadow-lg shadow-[#b27b1b]/20 transition hover:bg-[#986715] disabled:cursor-wait disabled:opacity-60">
                        <span wire:loading.remove wire:target="submit">{{ $isDirectFreeRegistration ? __('Confirm registration') : __('Continue to secure payment') }}</span>
                        <span wire:loading wire:target="submit">{{ __('Processing…') }}</span>
                    </button>
                    <p class="mt-3 text-center text-xs leading-5 text-slate-500">{{ __('Your admission is tied to your verified account. Payment provider pages may open in a new step.') }}</p>
                </section>
            </form>
        </div>
    </div>
</main>
