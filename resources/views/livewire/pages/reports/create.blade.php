@php
    $subjectLabel = strtolower($this->context['subject_label']);
@endphp

@section('title', __('Report :subject', ['subject' => $this->context['subject_label']]) . ' - ' . config('app.name'))

@include('partials.filament-assets', [
    'scripts' => ['filament/support', 'filament/schemas', 'filament/forms'],
])

<div class="min-h-screen bg-slate-50 py-4 pb-20 sm:py-6 lg:py-10">
    <div class="mx-auto w-full max-w-6xl px-3 sm:px-4 lg:px-6">
        <section class="rounded-3xl border border-slate-200 bg-white px-4 py-5 shadow-sm sm:px-6 sm:py-6 lg:px-8 lg:py-8">
            <p class="text-xs font-bold uppercase tracking-[0.22em] text-rose-600">{{ __('Safety & Trust') }}</p>
            <h1 class="mt-3 break-words font-heading text-2xl font-bold text-slate-900 sm:text-3xl">
                {{ __('Report a Record') }}
            </h1>
            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600 sm:text-base">
                {{ __('Use this when the record is fake, inaccurate, unsafe, or misleading. Reports go to moderation review.') }}
            </p>

            <div class="mt-6 rounded-3xl border border-rose-200 bg-rose-50 p-4 sm:p-5">
                <p class="text-xs font-bold uppercase tracking-[0.22em] text-rose-700">{{ __('Reporting') }}</p>
                <p class="mt-3 text-sm font-medium text-slate-600">{{ __('Selected :subject', ['subject' => $subjectLabel]) }}</p>
                <p class="mt-1 break-words text-lg font-semibold text-slate-950 sm:text-xl">{{ $this->context['subject_title'] }}</p>
                <p class="mt-3 text-sm leading-6 text-slate-600">
                    {{ __('Please confirm this is the :subject you want to report before submitting.', ['subject' => $subjectLabel]) }}
                </p>
                <a href="{{ $this->context['redirect_url'] }}"
                    class="mt-4 inline-flex w-full items-center justify-center rounded-xl border border-rose-200 bg-white px-4 py-3 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:text-rose-800 sm:w-auto">
                    {{ __('View this :subject', ['subject' => $subjectLabel]) }}
                </a>
            </div>

            <form wire:submit="submit" class="mt-6 space-y-5 sm:mt-8 sm:space-y-6">
                {{ $this->form }}

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <button type="submit"
                        class="inline-flex w-full items-center justify-center rounded-xl bg-rose-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-rose-700 sm:w-auto">
                        {{ __('Submit Report') }}
                    </button>
                </div>
            </form>
        </section>
    </div>
</div>
