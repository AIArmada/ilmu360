@section('title', __('Submit Speaker') . ' - ' . config('app.name'))

@include('partials.filament-assets', [
    'scripts' => ['filament/support', 'filament/schemas', 'filament/forms', 'filament/actions'],
])

@once
    @push('styles')
        <style>
            @media (max-width: 767px) {
                .mi-submit-speaker-form .fi-section {
                    border-radius: 1rem;
                    border-color: rgb(226 232 240 / 0.72);
                    background: rgb(255 255 255 / 0.96);
                    box-shadow: none;
                }

                .mi-submit-speaker-form .fi-section-header {
                    padding: 1rem 1rem 0.75rem;
                }

                .mi-submit-speaker-form .fi-section-content-ctn {
                    padding: 0 1rem 1rem;
                }

                .mi-submit-speaker-form .fi-section-content {
                    gap: 0.85rem;
                }

                .mi-submit-speaker-form .fi-input-wrp,
                .mi-submit-speaker-form .fi-select-input,
                .mi-submit-speaker-form .fi-select-control,
                .mi-submit-speaker-form .fi-fo-file-upload,
                .mi-submit-speaker-form .fi-fo-repeater-item {
                    border-radius: 0.95rem;
                }
            }
        </style>
    @endpush
@endonce

<div class="min-h-screen bg-slate-50 py-6 pb-24 sm:py-10 sm:pb-28">
    <div class="container mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <section class="rounded-3xl border border-slate-200/80 bg-white px-4 py-5 shadow-none sm:rounded-4xl sm:p-6 sm:shadow-sm md:p-8 lg:p-10">
            <p class="text-xs font-bold uppercase tracking-[0.22em] text-emerald-600">{{ __('Community Contribution') }}</p>
            <h1 class="mt-3 font-heading text-2xl font-bold text-slate-900 sm:text-3xl md:text-4xl">{{ __('Add a New Speaker') }}</h1>
            <p class="mt-3 w-full text-sm leading-6 text-slate-600 md:text-base">
                {{ __('Submit a new speaker record for the ilmu360° directory. We will notify you if it is approved or rejected.') }}
            </p>
            <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-slate-700">
                <p class="font-semibold text-slate-900">{{ __('Check the existing directory first') }}</p>
                <p class="mt-2 w-full leading-6">
                    {{ __('Before you submit, please check the existing speakers directory. If it already exists, submit an update instead of creating a new record.') }}
                </p>
                <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                    <a href="{{ route('speakers.index') }}" wire:navigate
                        class="inline-flex w-full items-center justify-center rounded-xl border border-amber-300 bg-white px-4 py-2.5 text-sm font-semibold text-amber-900 transition hover:border-amber-400 hover:bg-amber-100 sm:w-auto">
                        {{ __('Check Existing Speakers') }}
                    </a>
                </div>
            </div>

            <form wire:submit="submit" class="mi-submit-speaker-form mt-6 space-y-5 sm:mt-8 sm:space-y-6">
                {{ $this->form }}

                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                    <button type="submit"
                        class="inline-flex w-full items-center justify-center rounded-xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700 sm:w-auto">
                        {{ __('Submit Speaker') }}
                    </button>
                </div>
            </form>
        </section>
    </div>
</div>
