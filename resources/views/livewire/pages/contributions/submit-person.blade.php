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

        @php($formProgress = $this->formProgress())
        <div class="mb-6 overflow-hidden rounded-2xl border border-emerald-100 bg-white shadow-[0_24px_70px_-50px_rgba(6,95,70,0.65)]">
            <section
                wire:ignore
                x-data="{
                    progress: @js($formProgress),
                    trackedPaths: [
                        'name',
                        'gender',
                        'names',
                        'institutions',
                        'address',
                    ],
                    isFilled(value) {
                        if (Array.isArray(value)) {
                            return value.some((item) => this.isFilled(item));
                        }

                        if (value === null || value === undefined) {
                            return false;
                        }

                        return typeof value === 'string' ? value.trim() !== '' : true;
                    },
                    calculate(state) {
                        const current = state ?? {};
                        const currentAddress = current.address ?? {};

                        const checks = [
                            this.isFilled(current.name),
                            this.isFilled(current.gender),
                            this.isFilled(currentAddress.country_id),
                        ];

                        (Array.isArray(current.names) ? current.names : []).forEach((name) => {
                            if (name === null || name === undefined) {
                                return;
                            }

                            const entry = name ?? {};
                            checks.push(this.isFilled(entry.name_type));
                            checks.push(this.isFilled(entry.full_name));
                            checks.push(this.isFilled(entry.language_code));
                        });

                        (Array.isArray(current.institutions) ? current.institutions : []).forEach((institution) => {
                            if (institution === null || institution === undefined) {
                                return;
                            }

                            checks.push(this.isFilled(institution.institution_id));
                        });

                        return Math.round((checks.filter(Boolean).length / checks.length) * 100);
                    },
                    updateScheduled: false,
                    update() {
                        if (this.updateScheduled) {
                            return;
                        }

                        this.updateScheduled = true;

                        queueMicrotask(() => {
                            this.updateScheduled = false;
                            this.progress = this.calculate(this.$wire.data);
                        });
                    },
                    init() {
                        this.trackedPaths.forEach((path) => this.$wire.watch(`data.${path}`, () => this.update()));
                        this.update();
                    },
                }"
                class="border-b border-slate-100 bg-white p-5 sm:p-6"
                aria-labelledby="submit-person-progress-title"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">{{ __('Kemajuan borang') }}</p>
                        <h2 id="submit-person-progress-title" class="mt-1 text-lg font-semibold text-slate-950" x-text="`{{ __('Anda sudah bermula') }} — ${progress}% {{ __('lengkap') }}`">
                            {{ __('Anda sudah bermula — :percent% lengkap', ['percent' => $formProgress]) }}
                        </h2>
                        <p class="mt-1 text-sm text-slate-600">{{ __('Lengkapkan maklumat asas penceramah untuk membantu direktori yang lebih baik.') }}</p>
                    </div>
                    <span class="shrink-0 rounded-full bg-emerald-50 px-3 py-1 text-sm font-bold text-emerald-800" x-text="`${progress}%`">{{ $formProgress }}%</span>
                </div>

                <div
                    class="mt-4 h-2.5 overflow-hidden rounded-full bg-slate-100"
                    role="progressbar"
                    aria-label="{{ __('Kemajuan borang') }}"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    :aria-valuenow="progress"
                >
                    <div class="h-full rounded-full bg-emerald-600 transition-[width] duration-500" style="width: {{ $formProgress }}%" :style="`width: ${progress}%`"></div>
                </div>
            </section>
        </div>

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
