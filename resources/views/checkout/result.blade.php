@section('title', __('Checkout result') . ' - ' . config('app.name'))
@section('meta_robots', 'noindex, nofollow')

@php
    $completed = $session->status instanceof \AIArmada\Checkout\States\Completed;
    $awaitingPayment = $session->status instanceof \AIArmada\Checkout\States\AwaitingPayment;
    $failed = $session->status instanceof \AIArmada\Checkout\States\PaymentFailed;
    $paid = $order?->isPaid() ?? false;
    $freeConfirmation = $completed && ! $paid && $order !== null && (int) $order->grand_total <= 0;
@endphp

<main class="mx-auto max-w-6xl px-5 py-10 sm:px-8 lg:px-12">
    <div class="mx-auto max-w-4xl">
        <div class="rounded-3xl border border-[#173c34]/10 bg-white p-6 shadow-xl shadow-[#173c34]/5 sm:p-9">
            @if ($completed && $freeConfirmation)
                <div class="flex size-14 items-center justify-center rounded-2xl bg-emerald-100 text-2xl text-emerald-800" aria-hidden="true">✓</div>
                <p class="mt-6 text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('Registration confirmed') }}</p>
                <h1 class="mt-2 font-heading text-3xl font-semibold tracking-tight text-[#173c34] sm:text-4xl">{{ __('You are registered') }}</h1>
                <p class="mt-3 max-w-2xl leading-7 text-slate-600">{{ __('No payment was made. Your free admission is attached to your verified account and is ready to use.') }}</p>
            @elseif ($completed && $paid)
                <div class="flex size-14 items-center justify-center rounded-2xl bg-emerald-100 text-2xl text-emerald-800" aria-hidden="true">✓</div>
                <p class="mt-6 text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('Payment confirmed') }}</p>
                <h1 class="mt-2 font-heading text-3xl font-semibold tracking-tight text-[#173c34] sm:text-4xl">{{ __('Your order is complete') }}</h1>
                <p class="mt-3 max-w-2xl leading-7 text-slate-600">{{ __('Your payment was confirmed and your admissions are ready. Keep this order number for support or future policy requests.') }}</p>
            @elseif ($awaitingPayment)
                <div class="flex size-14 items-center justify-center rounded-2xl bg-amber-100 text-2xl text-amber-800" aria-hidden="true">…</div>
                <p class="mt-6 text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('Payment in progress') }}</p>
                <h1 class="mt-2 font-heading text-3xl font-semibold tracking-tight text-[#173c34] sm:text-4xl">{{ __('Finish payment with the provider') }}</h1>
                <p class="mt-3 max-w-2xl leading-7 text-slate-600">{{ __('We are waiting for the payment provider to confirm your payment. Refresh this page after completing the provider step.') }}</p>
            @else
                <div class="flex size-14 items-center justify-center rounded-2xl bg-rose-100 text-2xl text-rose-800" aria-hidden="true">!</div>
                <p class="mt-6 text-xs font-bold uppercase tracking-[0.2em] text-rose-700">{{ __('Checkout not completed') }}</p>
                <h1 class="mt-2 font-heading text-3xl font-semibold tracking-tight text-[#173c34] sm:text-4xl">{{ __('Payment or registration needs attention') }}</h1>
                <p class="mt-3 max-w-2xl leading-7 text-slate-600">{{ $session->error_message ?: __('No admission was issued from this attempt. You can return to the event and try again.') }}</p>
            @endif

            @if ($order !== null)
                <div class="mt-8 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl bg-[#f6f8f5] p-4">
                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">{{ __('Order number') }}</p>
                        <p class="mt-1 font-mono text-sm font-bold text-[#173c34]">{{ $order->order_number }}</p>
                    </div>
                    <div class="rounded-2xl bg-[#f6f8f5] p-4">
                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">{{ __('Amount') }}</p>
                        <p class="mt-1 text-lg font-bold text-[#173c34]">{{ $order->getFormattedGrandTotal() }}</p>
                    </div>
                    <div class="rounded-2xl bg-[#f6f8f5] p-4">
                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">{{ __('Status') }}</p>
                        <p class="mt-1 text-sm font-bold text-[#173c34]">{{ $paid ? __('Paid') : ($freeConfirmation ? __('Free confirmation') : __('Pending')) }}</p>
                    </div>
                </div>
            @endif

            @if ($paid && $order !== null)
                <a href="{{ route('checkout.receipt', ['session' => $session->getKey()]) }}"
                    data-signal-event="commerce.receipt_download_requested"
                    data-signal-category="commerce"
                    data-signal-component="checkout_result"
                    data-signal-control="download_receipt"
                    data-signal-entity-type="order"
                    data-signal-entity-id="{{ $order->getKey() }}"
                    class="mt-6 inline-flex w-full items-center justify-center rounded-xl bg-[#173c34] px-5 py-3.5 font-bold text-white transition hover:bg-[#21594c] sm:w-auto">
                    {{ __('Download receipt PDF') }}
                </a>
            @endif

            @if ($completed && $registrations->isNotEmpty())
                <section class="mt-10 border-t border-slate-100 pt-8" aria-labelledby="admissions-heading">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-[#b27b1b]">{{ __('Your access') }}</p>
                            <h2 id="admissions-heading" class="mt-1 text-2xl font-bold text-[#173c34]">{{ __('Admissions') }}</h2>
                        </div>
                        <a href="{{ route('dashboard') }}" class="text-sm font-bold text-[#173c34] underline decoration-[#b27b1b] underline-offset-4">{{ __('Open dashboard') }}</a>
                    </div>

                    <div class="mt-5 space-y-4">
                        @foreach ($registrations as $registration)
                            <article class="rounded-2xl border border-slate-200 p-5">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <h3 class="text-lg font-bold text-[#173c34]">{{ $registration->event?->title ?? __('Event admission') }}</h3>
                                        <p class="mt-1 text-sm text-slate-500">{{ __('Admission :number', ['number' => $registration->registration_no]) }}</p>
                                    </div>
                                    <span class="inline-flex w-fit rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-800">{{ __('Confirmed') }}</span>
                                </div>

                                @if ($registration->occurrence || $registration->session)
                                    <p class="mt-4 text-sm font-semibold text-slate-700">
                                        {{ $registration->session?->title ?? $registration->occurrence?->title ?? __('Scheduled admission') }}
                                    </p>
                                @endif

                                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                    @foreach ($registration->participants as $participant)
                                        <div class="rounded-xl bg-[#f6f8f5] px-4 py-3">
                                            <p class="font-semibold text-[#173c34]">{{ $participant->name }}</p>
                                            <p class="mt-1 text-xs text-slate-500">{{ $participant->is_purchaser ? __('Purchaser') : __('Participant') }}</p>
                                        </div>
                                    @endforeach
                                </div>

                                @if ($registration->passes->isNotEmpty())
                                    <div class="mt-4 flex flex-wrap gap-2">
                                        @foreach ($registration->passes as $pass)
                                            <a href="{{ route('events.pass', ['event' => $registration->event, 'pass' => $pass->getKey()]) }}"
                                                data-signal-event="commerce.admission_opened"
                                                data-signal-category="commerce"
                                                data-signal-component="checkout_result"
                                                data-signal-control="open_admission"
                                                data-signal-entity-type="pass"
                                                data-signal-entity-id="{{ $pass->getKey() }}"
                                                class="inline-flex items-center rounded-xl border border-[#173c34]/20 px-4 py-2 text-sm font-bold text-[#173c34] transition hover:bg-[#f6f8f5]">
                                                {{ __('Open admission :number', ['number' => $pass->pass_no]) }}
                                            </a>
                                        @endforeach
                                    </div>
                                @endif

                                @if ($paid && $registration->event instanceof \App\Models\Event && auth()->user() instanceof \App\Models\User && app(\App\Support\Events\EventCommercePolicy::class)->canSelfServiceRefund(auth()->user(), $registration->event, $registration))
                                    <a href="{{ route('events.registration.refund', ['event' => $registration->event, 'registration' => $registration]) }}"
                                        data-signal-event="commerce.event_refund_started"
                                        data-signal-category="commerce"
                                        data-signal-component="checkout_result"
                                        data-signal-control="start_refund"
                                        data-signal-entity-type="event_registration"
                                        data-signal-entity-id="{{ $registration->getKey() }}"
                                        class="mt-4 inline-flex items-center rounded-xl border border-rose-200 px-4 py-2 text-sm font-bold text-rose-700 transition hover:bg-rose-50">
                                        {{ __('Request refund for this admission') }}
                                    </a>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif

            <div class="mt-8 flex flex-col gap-3 border-t border-slate-100 pt-6 sm:flex-row">
                <a href="{{ route('dashboard') }}" class="inline-flex flex-1 items-center justify-center rounded-xl border border-slate-200 px-5 py-3 font-bold text-slate-700 transition hover:bg-slate-50">{{ __('Go to dashboard') }}</a>
                <a href="{{ route('events.index') }}" class="inline-flex flex-1 items-center justify-center rounded-xl bg-[#f6f8f5] px-5 py-3 font-bold text-[#173c34] transition hover:bg-emerald-50">{{ __('Browse more events') }}</a>
            </div>
        </div>
    </div>
</main>
