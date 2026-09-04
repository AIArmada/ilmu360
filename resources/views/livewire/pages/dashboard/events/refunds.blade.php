<main class="mx-auto max-w-7xl px-5 py-8 sm:px-8 lg:px-12">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <a href="{{ route('dashboard.events.participants', $event) }}" wire:navigate class="text-sm font-semibold text-[#173c34] underline decoration-[#b27b1b] underline-offset-4">← {{ __('Participants') }}</a>
            <p class="mt-6 text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('Event operations') }}</p>
            <h1 class="mt-2 font-heading text-3xl font-semibold tracking-tight text-[#173c34]">{{ __('Refunds') }}</h1>
            <p class="mt-2 max-w-2xl leading-7 text-slate-600">{{ __('Use this workspace for approved exceptions. Refunds are processed one admission at a time and remain pending until the payment provider confirms them.') }}</p>
        </div>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-950">{{ __('Refund policy is enabled for this event.') }}</div>
    </div>

    @if ($successMessage)
        <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-semibold text-emerald-800" role="status">{{ $successMessage }}</div>
    @endif

    @error('refund')
        <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ $message }}</div>
    @enderror

    <section class="mt-8 rounded-3xl border border-[#173c34]/10 bg-white p-5 shadow-lg shadow-[#173c34]/5 sm:p-7" aria-labelledby="eligible-admissions-heading">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 id="eligible-admissions-heading" class="text-xl font-bold text-[#173c34]">{{ __('Eligible paid admissions') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('Search by admission number, participant name, email, or phone. The list is limited to the latest 100 results.') }}</p>
            </div>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search admissions…') }}" class="w-full rounded-xl border-slate-200 px-4 py-3 text-sm shadow-sm sm:max-w-sm">
        </div>

        @error('registration')
            <p class="mt-4 text-sm font-semibold text-rose-700">{{ $message }}</p>
        @enderror

        <div class="mt-6 overflow-x-auto">
            <table class="w-full min-w-[680px] text-left text-sm">
                <thead class="border-b border-slate-100 text-xs uppercase tracking-[0.12em] text-slate-500">
                    <tr>
                        <th class="px-3 py-3 font-bold">{{ __('Participant') }}</th>
                        <th class="px-3 py-3 font-bold">{{ __('Admission') }}</th>
                        <th class="px-3 py-3 font-bold">{{ __('Amount') }}</th>
                        <th class="px-3 py-3 font-bold">{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($registrations as $registration)
                        @php($participant = $registration->participants->firstWhere('is_primary', true) ?? $registration->participants->first())
                        <tr>
                            <td class="px-3 py-4">
                                <p class="font-semibold text-[#173c34]">{{ $participant?->name ?: __('Unnamed participant') }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ $registration->resolvedEmail() ?: $registration->resolvedPhone() ?: __('No contact supplied') }}</p>
                            </td>
                            <td class="px-3 py-4">
                                <p class="font-mono text-xs font-bold text-[#173c34]">{{ $registration->registration_no }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ $registration->statusValue() }}</p>
                            </td>
                            <td class="px-3 py-4 font-bold text-[#173c34]">{{ $this->formatMoney($registration) }}</td>
                            <td class="px-3 py-4">
                                <button type="button" wire:click="selectRegistration('{{ $registration->getKey() }}')" class="rounded-xl border border-rose-200 px-3 py-2 text-xs font-bold text-rose-700 transition hover:bg-rose-50" data-signal-event="commerce.organizer_refund_started" data-signal-category="commerce" data-signal-component="event_refunds" data-signal-control="select_admission" data-signal-entity-type="event_registration" data-signal-entity-id="{{ $registration->getKey() }}">{{ __('Review refund') }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-10 text-center text-sm text-slate-500">{{ __('No eligible paid admissions found.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($selectedRegistration)
        <section class="mt-8 rounded-3xl border border-rose-200 bg-rose-50 p-5 sm:p-7" aria-labelledby="approve-refund-heading">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.15em] text-rose-700">{{ __('Organizer approval') }}</p>
                    <h2 id="approve-refund-heading" class="mt-1 text-xl font-bold text-rose-950">{{ __('Refund :number', ['number' => $selectedRegistration->registration_no]) }}</h2>
                    <p class="mt-1 text-sm text-rose-900">{{ $this->formatMoney($selectedRegistration) }} · {{ $selectedRegistration->participants->firstWhere('is_primary', true)?->name ?: __('Unnamed participant') }}</p>
                </div>
                <button type="button" wire:click="clearSelection" class="text-sm font-bold text-rose-700 underline underline-offset-4">{{ __('Cancel') }}</button>
            </div>

            <form wire:submit="submit" class="mt-6">
                <label for="refund-reason" class="text-sm font-bold text-rose-950">{{ __('Reason for this exception') }}</label>
                <textarea id="refund-reason" wire:model="reason" rows="3" maxlength="500" class="mt-2 w-full rounded-xl border-rose-200 bg-white px-4 py-3 text-sm shadow-sm" placeholder="{{ __('Explain why the organizer approved this refund…') }}"></textarea>
                @error('reason')<p class="mt-2 text-sm font-semibold text-rose-700">{{ $message }}</p>@enderror
                @error('selectedRegistrationId')<p class="mt-2 text-sm font-semibold text-rose-700">{{ $message }}</p>@enderror
                <button type="submit" wire:loading.attr="disabled" wire:target="submit" class="mt-4 rounded-xl bg-rose-700 px-5 py-3 text-sm font-bold text-white transition hover:bg-rose-800 disabled:cursor-wait disabled:opacity-60" data-signal-event="commerce.organizer_refund_submitted" data-signal-category="commerce" data-signal-component="event_refunds" data-signal-control="submit_refund" data-signal-entity-type="event_registration" data-signal-entity-id="{{ $selectedRegistration->getKey() }}">
                    <span wire:loading.remove wire:target="submit">{{ __('Approve and process refund') }}</span>
                    <span wire:loading wire:target="submit">{{ __('Processing…') }}</span>
                </button>
            </form>
        </section>
    @endif
</main>
