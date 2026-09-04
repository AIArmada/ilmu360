@section('title', __('Registration confirmed') . ' - ' . config('app.name'))
@section('meta_robots', 'noindex, nofollow')

<main class="mx-auto max-w-6xl px-5 py-10 sm:px-8 lg:px-12">
    <div class="mx-auto max-w-3xl rounded-3xl border border-[#173c34]/10 bg-white p-6 shadow-xl shadow-[#173c34]/5 sm:p-9">
        <div class="flex size-14 items-center justify-center rounded-2xl bg-emerald-100 text-2xl text-emerald-800" aria-hidden="true">✓</div>
        <p class="mt-6 text-xs font-bold uppercase tracking-[0.2em] text-[#b27b1b]">{{ __('Registration confirmed') }}</p>
        <h1 class="mt-2 font-heading text-3xl font-semibold tracking-tight text-[#173c34] sm:text-4xl">{{ __('Your free admission is ready') }}</h1>
        <p class="mt-3 leading-7 text-slate-600">{{ __('No payment was made. Save this page or open your admission whenever you need it from your dashboard.') }}</p>

        <div class="mt-8 space-y-4">
            @foreach ($registrations as $registration)
                <article class="rounded-2xl border border-slate-200 p-5">
                    <h2 class="text-xl font-bold text-[#173c34]">{{ $registration->event?->title ?? __('Event admission') }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ __('Admission :number', ['number' => $registration->registration_no]) }}</p>

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
                                    data-signal-component="free_checkout_result"
                                    data-signal-control="open_admission"
                                    data-signal-entity-type="pass"
                                    data-signal-entity-id="{{ $pass->getKey() }}"
                                    class="mt-1 inline-flex items-center rounded-xl bg-[#173c34] px-4 py-2 text-sm font-bold text-white transition hover:bg-[#21594c]">
                                    {{ __('Open admission :number', ['number' => $pass->pass_no]) }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                </article>
            @endforeach
        </div>

        <div class="mt-8 flex flex-col gap-3 border-t border-slate-100 pt-6 sm:flex-row">
            <a href="{{ route('dashboard') }}" class="inline-flex flex-1 items-center justify-center rounded-xl bg-[#173c34] px-5 py-3 font-bold text-white transition hover:bg-[#21594c]">{{ __('Go to dashboard') }}</a>
            <a href="{{ route('events.index') }}" class="inline-flex flex-1 items-center justify-center rounded-xl bg-[#f6f8f5] px-5 py-3 font-bold text-[#173c34] transition hover:bg-emerald-50">{{ __('Browse more events') }}</a>
        </div>
    </div>
</main>
