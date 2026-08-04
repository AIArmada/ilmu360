@section('title', __('Submit Speaker') . ' - ' . config('app.name'))

@include('partials.filament-assets', [
    'scripts' => ['filament/support', 'filament/schemas', 'filament/forms', 'filament/actions'],
])

@once
    @push('styles')
        <style>
            @media (max-width: 767px) {
                .mi-submit-person-form .fi-section {
                    border-radius: 1rem;
                    border-color: rgb(226 232 240 / 0.72);
                    background: rgb(255 255 255 / 0.96);
                    box-shadow: none;
                }

                .mi-submit-person-form .fi-section-header {
                    padding: 1rem 1rem 0.75rem;
                }

                .mi-submit-person-form .fi-section-content-ctn {
                    padding: 0 1rem 1rem;
                }

                .mi-submit-person-form .fi-section-content {
                    gap: 0.85rem;
                }

                .mi-submit-person-form .fi-input-wrp,
                .mi-submit-person-form .fi-select-input,
                .mi-submit-person-form .fi-select-control,
                .mi-submit-person-form .fi-fo-file-upload,
                .mi-submit-person-form .fi-fo-repeater-item {
                    border-radius: 0.95rem;
                }
            }
        </style>
    @endpush
@endonce

<div class="bg-white pb-0">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-2 px-4 py-2 sm:gap-3 sm:px-6 sm:py-4 lg:px-8 lg:py-6">
        <header class="space-y-2 sm:space-y-3">
            <p class="text-xs font-bold uppercase tracking-[0.22em] text-emerald-600">{{ __('Community Contribution') }}</p>
            <h1 class="font-heading text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl lg:text-5xl">{{ __('Add a New Speaker') }}</h1>
            <p class="w-full text-sm leading-6 text-slate-600 md:text-base">
                {{ __('Submit a new speaker record for the ilmu360° directory. We will notify you if it is approved or rejected.') }}
            </p>
        </header>

        <section class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4 shadow-sm sm:rounded-3xl sm:px-5 sm:py-5">
            <p class="text-xs font-bold uppercase tracking-[0.22em] text-amber-700">{{ __('Check the existing directory first') }}</p>
            <p class="mt-2 text-sm leading-6 text-amber-900">
                {{ __('Before you submit, please check the existing speakers directory. If it already exists, submit an update instead of creating a new record.') }}
            </p>
            <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                <a href="{{ route('persons.index') }}" wire:navigate
                    class="inline-flex w-full items-center justify-center rounded-xl border border-amber-300 bg-white px-4 py-2.5 text-sm font-semibold text-amber-900 transition hover:border-amber-400 hover:bg-amber-100 sm:w-auto">
                    {{ __('Check Existing Speakers') }}
                </a>
            </div>
        </section>

        <form wire:submit="submit" class="mi-submit-person-form space-y-2 sm:space-y-3">
            {{ $this->form }}

            <div class="flex flex-col gap-3 pt-0 sm:flex-row sm:items-center">
                <button type="submit"
                    class="inline-flex w-full items-center justify-center rounded-xl bg-emerald-600 px-5 py-3.5 text-sm font-semibold text-white transition hover:bg-emerald-700 sm:w-auto">
                    {{ __('Submit Speaker') }}
                </button>
            </div>
        </form>

        <x-filament-actions::modals />
    </div>
</div>
