@section('title', __('Hantar Majlis') . ' - ' . config('app.name'))

@include('partials.filament-assets', [
    'scripts' => ['filament/support', 'filament/schemas', 'filament/forms', 'filament/actions', 'filament/notifications'],
])

@push('head')
<style>
    /* Keep the wizard calm and usable on smaller screens. */
    .fi-sc-wizard-header {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
        gap: 0.5rem;
        padding: 0.5rem;
        border: 1px solid #e2e8f0;
        border-radius: 1rem;
        background: #ffffff;
    }

    .fi-sc-wizard-header-step-btn {
        min-width: 10rem;
        border-radius: 0.75rem;
    }

    .fi-sc-wizard-header::-webkit-scrollbar {
        height: 3px;
    }

    .fi-sc-wizard-header::-webkit-scrollbar-thumb {
        background-color: #cbd5e1;
        border-radius: 9999px;
    }

    /* Style grouped select dropdown */
    .fi-dropdown-header {
        font-weight: 600;
        color: #64748b;
        font-size: 0.875rem;
        padding: 0.5rem 0.75rem;
    }

    .fi-dropdown-list {
        padding-left: 0;
    }

    .fi-dropdown-list-item {
        padding-left: 1.5rem !important;
    }

    /* Hide loading indicators by default - Livewire will show them during actual loading */
    .fi-loading-indicator {
        display: none;
    }
</style>
@endpush

<div class="min-h-screen bg-[#f6f8f6] py-10 pb-32 sm:py-14">
    <div class="container mx-auto px-6 lg:px-12">
        <div class="mx-auto max-w-6xl xl:max-w-7xl">
            @if(($eventContainer = $this->selectedEventContainer()) instanceof \App\Models\Event)
                <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50/70 p-5 shadow-sm">
                    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">{{ __('Event') }}</p>
                            <h2 class="mt-2 font-heading text-2xl font-bold text-emerald-950">{{ $eventContainer->title }}</h2>
                            <p class="mt-2 text-sm text-emerald-900/75">
                                {{ __('This submission will be added as a session in the selected event occurrence.') }}
                            </p>
                        </div>
                        @if($eventManagementUrl = $this->eventManagementUrl())
                            <a href="{{ $eventManagementUrl }}"
                                class="inline-flex h-11 items-center justify-center rounded-xl border border-emerald-300 bg-white px-5 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-100/70">
                                {{ __('Back to Event') }}
                            </a>
                        @endif
                    </div>
                </div>
            @endif

            <header class="mx-auto mb-8 max-w-3xl text-center sm:mb-10">
                <p class="text-xs font-bold uppercase tracking-[0.24em] text-emerald-700">{{ __('Hantar Majlis') }}</p>
                <h1 class="mt-3 font-heading text-4xl font-bold leading-tight text-slate-950 sm:text-5xl">{{ __('Hantar Majlis') }}</h1>
                <p class="mt-4 text-base leading-7 text-slate-600 sm:text-lg">
                    {{ __('Percuma untuk dihantar') }} · {{ __('Semakan sebelum diterbitkan') }}
                </p>
                <div class="mt-5 flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-sm text-slate-500">
                    <span class="inline-flex items-center gap-2"><span class="size-2 rounded-full bg-emerald-500"></span>{{ __('Percuma untuk dihantar') }}</span>
                    <span class="inline-flex items-center gap-2"><span class="size-2 rounded-full bg-emerald-500"></span>{{ __('Semakan sebelum diterbitkan') }}</span>
                </div>
            </header>

            <section class="mb-8 overflow-hidden rounded-3xl border border-emerald-100 bg-white shadow-[0_24px_70px_-50px_rgba(6,95,70,0.65)]" aria-labelledby="poster-assist-title">
                <div class="grid gap-0 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <div class="p-6 sm:p-8">
                        <div class="flex items-start gap-4">
                            <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700" aria-hidden="true">
                                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 16.5 7.5 12.75l3 3L15.75 10.5l4.5 4.5M3.75 19.5h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z" />
                                </svg>
                            </span>
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">{{ __('Pilihan lebih pantas') }}</p>
                                <h2 id="poster-assist-title" class="mt-2 font-heading text-2xl font-bold text-slate-950">{{ __('Ada poster? Biar kami bantu isi.') }}</h2>
                                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                    {{ __('Muat naik poster atau PDF majlis. Kami akan cuba baca maklumatnya supaya anda hanya perlu semak dan betulkan.') }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center">
                            <label for="submit-event-source-attachment" class="sr-only">
                                {{ __('Pilih poster, gambar, atau PDF majlis') }}
                            </label>

                            <input id="submit-event-source-attachment" type="file" wire:model="event_source_attachment"
                                accept=".pdf,image/jpeg,image/png,image/webp"
                                aria-describedby="submit-event-source-attachment-help"
                                class="block min-h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-white file:px-3 file:py-2 file:text-sm file:font-semibold file:text-emerald-800 file:shadow-sm hover:file:bg-emerald-50">

                            <x-filament::button type="button" wire:click="extractEventFromMedia" wire:loading.attr="disabled"
                                wire:target="event_source_attachment,extractEventFromMedia"
                                data-signal-event="submission.poster_extraction_started"
                                data-signal-category="submission"
                                data-signal-component="submit_event_form"
                                data-signal-control="extract_poster"
                                class="min-h-12 whitespace-nowrap">
                                <span wire:loading.remove wire:target="extractEventFromMedia">{{ __('Ekstrak Dengan AI') }}</span>
                                <span wire:loading wire:target="extractEventFromMedia">{{ __('Sedang membaca...') }}</span>
                            </x-filament::button>
                        </div>

                        @error('event_source_attachment')
                            <p class="mt-3 text-sm text-danger-600">{{ $message }}</p>
                        @enderror

                        <p id="submit-event-source-attachment-help" class="mt-3 text-xs leading-5 text-slate-500">
                            {{ __('PDF, JPEG, PNG, atau WEBP. Pastikan poster jelas supaya maklumat mudah dibaca.') }}
                        </p>

                        <p wire:loading wire:target="event_source_attachment" class="mt-2 text-sm text-emerald-700">
                            {{ __('Fail sedang dimuat naik...') }}
                        </p>
                        <p wire:loading wire:target="extractEventFromMedia" class="mt-2 text-sm text-emerald-700">
                            {{ __('Sedang mengekstrak maklumat daripada fail...') }}
                        </p>
                    </div>

                    <div class="border-t border-emerald-100 bg-emerald-50/50 p-6 sm:p-8 lg:border-l lg:border-t-0">
                        <p class="text-sm font-semibold text-emerald-950">{{ __('Tiga langkah mudah') }}</p>
                        <ol class="mt-5 space-y-4">
                            @foreach([
                                __('Muat naik poster'),
                                __('Semak maklumat'),
                                __('Hantar untuk semakan'),
                            ] as $step => $label)
                                <li class="flex items-center gap-3 text-sm text-slate-700">
                                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-white text-xs font-bold text-emerald-800 shadow-sm ring-1 ring-emerald-100">{{ $step + 1 }}</span>
                                    <span>{{ $label }}</span>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                </div>
            </section>

            @php($formProgress = $this->formProgress())
            @php($progressConfiguration = $this->clientProgressConfiguration())
            <section
                wire:ignore
                x-data="{
                    progress: @js($formProgress),
                    config: @js($progressConfiguration),
                    trackedPaths: [
                        'event_category_ids',
                        'domain_tags',
                        'title',
                        'submission_country_id',
                        'event_date',
                        'prayer_time',
                        'custom_time',
                        'event_format',
                        'visibility',
                        'gender',
                        'age_group',
                        'languages',
                        'primary_organizer_id',
                        'primary_organizer_kind',
                        'primary_organizer_institution_id',
                        'primary_organizer_person_id',
                        'location_same_as_institution',
                        'location_type',
                        'location_institution_id',
                        'location_venue_id',
                        'persons',
                        'other_key_people',
                        'submitter_name',
                        'submitter_email',
                        'submitter_phone',
                    ],
                    progressEventHandler: null,
                    isFilled(value) {
                        if (Array.isArray(value)) {
                            return value.some((item) => this.isFilled(item));
                        }

                        if (value === null || value === undefined) {
                            return false;
                        }

                        return typeof value === 'string' ? value.trim() !== '' : true;
                    },
                    selectedIds(value) {
                        const values = Array.isArray(value) ? value : [value];

                        return values
                            .filter((item) => item !== null && item !== undefined && item !== '')
                            .map((item) => typeof item === 'object' && item.value !== undefined ? item.value : item)
                            .map((item) => String(item));
                    },
                    calculate(state) {
                        const hasSelection = (value) => this.selectedIds(value).length > 0;
                        const intersects = (left, right) => left.some((value) => right.includes(value));
                        const currentState = state ?? {};
                        const categoryIds = this.selectedIds(currentState.event_category_ids);
                        const topicIds = this.selectedIds(currentState.domain_tags);
                        const religious = intersects(categoryIds, this.config.religious_category_ids)
                            || intersects(topicIds, this.config.religious_topic_ids);
                        const eventFormat = currentState.event_format?.value ?? currentState.event_format;
                        const isOnline = eventFormat === 'online';
                        const prayerTime = currentState.prayer_time?.value ?? currentState.prayer_time;
                        const organizerId = currentState.primary_organizer_id;
                        const organizerKind = ['institution', 'person'].includes(currentState.primary_organizer_kind)
                            ? currentState.primary_organizer_kind
                            : (this.isFilled(organizerId) ? 'institution' : null);
                        const sameAsInstitution = currentState.location_same_as_institution === undefined
                            || currentState.location_same_as_institution === null
                            ? true
                            : Boolean(currentState.location_same_as_institution);
                        const locationRequired = ! isOnline && (
                            organizerKind === 'person' || ! sameAsInstitution
                        );

                        const requiredFields = [
                            hasSelection(categoryIds),
                            hasSelection(topicIds),
                            this.isFilled(currentState.title),
                            this.isFilled(currentState.submission_country_id),
                            this.isFilled(currentState.event_date),
                            this.isFilled(eventFormat),
                            this.isFilled(currentState.visibility),
                            this.isFilled(currentState.gender),
                            hasSelection(currentState.age_group),
                            hasSelection(currentState.languages),
                        ];

                        if (religious) {
                            requiredFields.push(this.isFilled(prayerTime));

                            if (prayerTime === 'lain_waktu') {
                                requiredFields.push(this.isFilled(currentState.custom_time));
                            }
                        } else {
                            requiredFields.push(this.isFilled(currentState.custom_time));
                        }

                        if (! this.config.has_scoped_institution && ! this.isFilled(organizerId)) {
                            requiredFields.push(this.isFilled(currentState.primary_organizer_kind));

                            if (organizerKind === 'institution') {
                                requiredFields.push(this.isFilled(currentState.primary_organizer_institution_id));
                            }

                            if (organizerKind === 'person') {
                                requiredFields.push(this.isFilled(currentState.primary_organizer_person_id));
                            }
                        }

                        if (locationRequired) {
                            requiredFields.push(this.isFilled(currentState.location_type));

                            if (currentState.location_type === 'institution') {
                                requiredFields.push(this.isFilled(currentState.location_institution_id));
                            }

                            if (currentState.location_type === 'venue') {
                                requiredFields.push(this.isFilled(currentState.location_venue_id));
                            }
                        }

                        if (hasSelection(categoryIds) && intersects(categoryIds, this.config.speaker_required_category_ids)) {
                            requiredFields.push(hasSelection(currentState.persons));
                        }

                        const otherKeyPeople = Array.isArray(currentState.other_key_people)
                            ? currentState.other_key_people
                            : [];

                        otherKeyPeople.forEach((keyPerson) => {
                            requiredFields.push(this.isFilled(keyPerson?.role_code));
                            requiredFields.push(this.isFilled(keyPerson?.involveable_id) || this.isFilled(keyPerson?.display_name));
                            requiredFields.push(this.isFilled(keyPerson?.visibility));
                        });

                        if (! this.config.is_authenticated) {
                            requiredFields.push(this.isFilled(currentState.submitter_name));
                            requiredFields.push(this.isFilled(currentState.submitter_email) || this.isFilled(currentState.submitter_phone));
                        }

                        return Math.round((requiredFields.filter(Boolean).length / requiredFields.length) * 100);
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
                        this.progressEventHandler = () => this.update();
                        window.addEventListener('submit-event-progress-updated', this.progressEventHandler);

                        this.trackedPaths.forEach((path) => this.$wire.watch(`data.${path}`, () => this.update()));

                        this.update();
                    },
                    destroy() {
                        if (!this.progressEventHandler) {
                            return;
                        }

                        window.removeEventListener('submit-event-progress-updated', this.progressEventHandler);
                    },
                }"
                data-submit-event-progress="{{ $formProgress }}"
                :data-submit-event-progress="progress"
                data-progress-client="true"
                class="mb-8 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
                aria-labelledby="submit-event-progress-title"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">{{ __('Kemajuan borang') }}</p>
                        <h2 id="submit-event-progress-title" class="mt-1 text-lg font-semibold text-slate-950" x-text="`{{ __('Anda sudah bermula') }} — ${progress}% {{ __('lengkap') }}`">
                            {{ __('Anda sudah bermula — :percent% lengkap', ['percent' => $formProgress]) }}
                        </h2>
                        <p class="mt-1 text-sm text-slate-600">{{ __('Pilihan asas sudah disediakan. Lengkapkan maklumat majlis yang khusus sahaja.') }}</p>
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

            <form wire:submit="submit" novalidate
                data-signal-submit-event="submission.manual_event_submitted"
                data-signal-category="submission"
                data-signal-component="submit_event_form"
                data-signal-control="submit">
                {{ $this->form }}

                @if(config('services.turnstile.enabled') && filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key')))
                    <div class="mt-6 rounded-2xl border border-slate-200 bg-white px-4 py-4">
                        <p class="mb-3 text-sm font-semibold text-slate-700">{{ __('Pengesahan Keselamatan') }}</p>
                        <p class="mb-3 text-xs text-slate-500">
                            {{ __('Sila sahkan anda bukan robot sebelum menghantar majlis.') }}
                        </p>
                        <input id="submit-event-captcha-token" type="hidden" wire:model.live="data.captcha_token">
                        <div id="submit-event-turnstile" wire:ignore></div>

                        @error('data.captcha_token')
                            <p class="mt-2 text-sm text-danger-600">{{ $message }}</p>
                        @enderror
                    </div>
                @endif
            </form>

            <x-filament-actions::modals />
        </div>
    </div>
</div>

@push('scripts')
    <script>
        (() => {
            if (window.__submitEventA11yBooted) {
                return;
            }

            window.__submitEventA11yBooted = true;

            const normalizeText = (value) => (value ?? '').replace(/\s+/g, ' ').trim();

            const applySubmitEventAccessibilityFixes = () => {
                document.querySelectorAll('.fi-fo-rich-editor').forEach((field) => {
                    const fieldWrapper = field.closest('[data-field-wrapper]');
                    const label = normalizeText(fieldWrapper?.querySelector('.fi-fo-field-label')?.textContent)
                        .replace(/\*$/, '')
                        .trim();
                    const editor = field.querySelector('.tiptap[contenteditable="true"]');

                    if (editor && label) {
                        editor.setAttribute('aria-label', label);
                        editor.setAttribute('title', label);
                    }
                });

                document.querySelectorAll('.fi-sc-wizard-header-step-btn[role="step"]').forEach((button) => {
                    button.removeAttribute('role');

                    const text = normalizeText(button.innerText);

                    if (text) {
                        button.setAttribute('aria-label', text);
                    }
                });

                document.querySelectorAll('button.fi-select-input-btn').forEach((button) => {
                    const valueText = normalizeText(button.innerText);
                    const labelledByIds = (button.getAttribute('aria-labelledby') ?? '')
                        .split(/\s+/)
                        .filter(Boolean);
                    const valueNode = button.querySelector('.fi-select-input-value-ctn > *')
                        ?? button.querySelector('.fi-select-input-value-ctn');

                    if (valueNode && valueText) {
                        const valueNodeId = valueNode.id || `${button.id}-value`;
                        valueNode.id = valueNodeId;

                        if (! labelledByIds.includes(valueNodeId)) {
                            labelledByIds.push(valueNodeId);
                        }
                    }

                    if (labelledByIds.length > 0) {
                        button.setAttribute('aria-labelledby', labelledByIds.join(' '));
                    }

                    button.removeAttribute('aria-label');
                });
            };

            const boot = () => window.requestAnimationFrame(applySubmitEventAccessibilityFixes);

            document.addEventListener('DOMContentLoaded', boot);
            document.addEventListener('livewire:navigated', boot);

            const observer = new MutationObserver(() => boot());
            observer.observe(document.body, {
                childList: true,
                subtree: true,
            });

            boot();
        })();
    </script>
@endpush

@if(config('services.turnstile.enabled') && filled(config('services.turnstile.site_key')))
    @push('scripts')
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" async defer></script>
        <script>
            (() => {
                let isRendered = false;

                const setToken = (token) => {
                    const tokenInput = document.getElementById('submit-event-captcha-token');

                    if (!tokenInput) {
                        return;
                    }

                    tokenInput.value = token;
                    tokenInput.dispatchEvent(new Event('input', {
                        bubbles: true
                    }));
                };

                const renderTurnstile = () => {
                    const container = document.getElementById('submit-event-turnstile');

                    if (!container || isRendered || typeof window.turnstile === 'undefined') {
                        return;
                    }

                    window.turnstile.render(container, {
                        sitekey: '{{ config('services.turnstile.site_key') }}',
                        callback: (token) => setToken(token),
                        'expired-callback': () => setToken(''),
                        'error-callback': () => setToken(''),
                    });

                    isRendered = true;
                };

                const boot = () => {
                    renderTurnstile();

                    window.setTimeout(renderTurnstile, 400);
                    window.setTimeout(renderTurnstile, 1200);
                };

                document.addEventListener('DOMContentLoaded', boot);
                document.addEventListener('livewire:navigated', boot);
            })();
        </script>
    @endpush
@endif
