<main class="mx-auto max-w-3xl px-5 py-10 sm:px-8 lg:py-16">
    <div class="rounded-3xl border border-[#173c34]/10 bg-white p-6 shadow-xl shadow-[#173c34]/5 sm:p-9">
        @if ($submitted)
            <div class="flex size-14 items-center justify-center rounded-2xl bg-emerald-100 text-2xl text-emerald-800" aria-hidden="true">✓</div>
            <p class="mt-6 text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('Refund') }}</p>
            <h1 class="mt-2 font-heading text-3xl font-semibold tracking-tight text-[#173c34]">{{ $this->statusLabel() }}</h1>
            <p class="mt-3 leading-7 text-slate-600">
                {{ $refundStatus === 'pending'
                    ? __('We have recorded your request. The admission stays reserved until the payment provider confirms the refund.')
                    : __('The admission has been refunded and can no longer be used.') }}
            </p>

            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <a href="{{ route('dashboard') }}" class="inline-flex flex-1 items-center justify-center rounded-xl bg-[#173c34] px-5 py-3.5 font-bold text-white transition hover:bg-[#21594c]">{{ __('Open dashboard') }}</a>
                <a href="{{ route('events.show', ['event' => $event->slug]) }}" class="inline-flex flex-1 items-center justify-center rounded-xl border border-slate-200 px-5 py-3.5 font-bold text-slate-700 transition hover:bg-slate-50">{{ __('Back to event') }}</a>
            </div>
        @else
            <a href="{{ route('events.show', ['event' => $event->slug]) }}" class="text-sm font-bold text-[#173c34] underline decoration-[#b27b1b] underline-offset-4">← {{ __('Back to event') }}</a>
            <p class="mt-8 text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('Refund request') }}</p>
            <h1 class="mt-2 font-heading text-3xl font-semibold tracking-tight text-[#173c34] sm:text-4xl">{{ __('Request a refund') }}</h1>
            <p class="mt-3 leading-7 text-slate-600">{{ __('Review the admission carefully. This action refunds this admission only; other admissions in the same order are not affected.') }}</p>

            <section class="mt-8 rounded-2xl bg-[#f6f8f5] p-5" aria-labelledby="refund-summary">
                <h2 id="refund-summary" class="text-lg font-bold text-[#173c34]">{{ $event->title }}</h2>
                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-slate-500">{{ __('Admission') }}</dt>
                        <dd class="mt-1 font-mono font-bold text-[#173c34]">{{ $registration->registration_no }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">{{ __('Amount to refund') }}</dt>
                        <dd class="mt-1 font-bold text-[#173c34]">{{ $this->refundAmount() }}</dd>
                    </div>
                </dl>
            </section>

            <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-amber-950">
                {{ __('Online refunds are available until :deadline. Once submitted, the request cannot be withdrawn online.', ['deadline' => $this->refundDeadlineLabel()]) }}
            </div>

            @error('refund')
                <div class="mt-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ $message }}</div>
            @enderror

            <form wire:submit="submit" class="mt-8">
                <label class="flex items-start gap-3 text-sm leading-6 text-slate-700">
                    <input type="checkbox" wire:model="confirmation" class="mt-1 size-4 rounded border-slate-300 text-[#173c34] focus:ring-[#173c34]">
                    <span>{{ __('I confirm that I am the purchaser, I want this admission refunded, and I understand that the admission will no longer be valid once the refund is confirmed.') }}</span>
                </label>
                @error('confirmation')
                    <p class="mt-2 text-sm font-semibold text-rose-700">{{ $message }}</p>
                @enderror

                <button type="submit" wire:loading.attr="disabled" wire:target="submit" class="mt-6 inline-flex w-full items-center justify-center rounded-xl bg-rose-700 px-5 py-3.5 font-bold text-white transition hover:bg-rose-800 disabled:cursor-wait disabled:opacity-60" data-signal-event="commerce.event_refund_requested" data-signal-category="commerce" data-signal-component="event_refund" data-signal-control="submit_refund" data-signal-entity-type="event_registration" data-signal-entity-id="{{ $registration->getKey() }}">
                    <span wire:loading.remove wire:target="submit">{{ __('Request refund') }}</span>
                    <span wire:loading wire:target="submit">{{ __('Submitting…') }}</span>
                </button>
            </form>
        @endif
    </div>
</main>
