@section('title', __('Claim Membership') . ' - ' . config('app.name'))

@include('partials.filament-assets', [
    'scripts' => ['filament/support', 'filament/schemas', 'filament/forms'],
])

<div class="min-h-screen bg-slate-50 py-10 pb-28">
    <div class="container mx-auto max-w-4xl px-6 lg:px-8">
        <section class="rounded-4xl border border-slate-200 bg-white p-6 shadow-sm md:p-8">
            <p class="text-xs font-bold uppercase tracking-[0.22em] text-emerald-600">{{ __('Membership') }}</p>
            <h1 class="mt-3 font-heading text-3xl font-bold text-slate-900">{{ __('Claim Membership') }}</h1>
            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                {{ __('Use this form when you belong to this record and need access to help maintain it. Claims are reviewed by moderators before membership is granted.') }}
            </p>

            <div class="mt-6 rounded-3xl border border-emerald-200 bg-emerald-50 p-5">
                <p class="text-xs font-bold uppercase tracking-[0.22em] text-emerald-700">{{ __('Selected Record') }}</p>
                <p class="mt-3 text-sm font-medium text-slate-600">{{ __('Claiming access for this :subject', ['subject' => strtolower($this->context['subject_label'])]) }}</p>
                <p class="mt-1 text-xl font-semibold text-slate-950">{{ $this->context['subject_title'] }}</p>
                <p class="mt-3 text-sm leading-6 text-slate-600">
                    {{ __('Please confirm this is the :subject you want to claim before submitting.', ['subject' => strtolower($this->context['subject_label'])]) }}
                </p>
                <a href="{{ $this->context['redirect_url'] }}"
                    class="mt-4 inline-flex items-center justify-center rounded-xl border border-emerald-200 bg-white px-4 py-2 text-sm font-semibold text-emerald-700 transition hover:border-emerald-300 hover:text-emerald-800">
                    {{ __('View this :subject', ['subject' => strtolower($this->context['subject_label'])]) }}
                </a>
            </div>

            <form wire:submit="submit" class="mt-8 space-y-6">
                {{ $this->form }}

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit"
                        class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">
                        {{ __('Submit Claim') }}
                    </button>
                    <a href="{{ route('membership-claims.index') }}" wire:navigate
                        class="inline-flex items-center justify-center rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50">
                        {{ __('My Claims') }}
                    </a>
                </div>
            </form>
        </section>

        <section class="mt-8 rounded-4xl border border-slate-200 bg-slate-950 p-6 text-white shadow-sm md:p-7">
            <p class="text-xs font-bold uppercase tracking-[0.22em] text-emerald-300">{{ __('Review notes') }}</p>
            <div class="mt-5 space-y-4 text-sm text-slate-300">
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p class="font-semibold text-white">{{ __('Claims do not grant instant access') }}</p>
                    <p class="mt-2">{{ __('Your proof is reviewed first. Access is only added after an admin or moderator approves the claim.') }}</p>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p class="font-semibold text-white">{{ __('Reviewers choose the final role') }}</p>
                    <p class="mt-2">{{ __('Moderators may approve you as editor, admin, or owner based on the strength of the evidence you upload.') }}</p>
                </div>
            </div>
        </section>
    </div>
</div>
