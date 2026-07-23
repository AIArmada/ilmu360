@section('title', __('Hantar Majlis') . ' - ' . config('app.name'))

@include('partials.filament-assets', [
    'scripts' => ['filament/support', 'filament/schemas', 'filament/forms', 'filament/actions', 'filament/notifications'],
])

<style>
    /* Ensure wizard stepper header doesn't clip */
    .fi-sc-wizard-header {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
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

<div class="bg-slate-50 min-h-screen py-12 pb-32">
    <div class="container mx-auto px-6 lg:px-12">
        <div class="max-w-6xl xl:max-w-7xl mx-auto">
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

            <!-- Header -->
            <div class="text-center mb-12">
                <h1 class="font-heading text-4xl font-bold text-slate-900">{{ __('Hantar Majlis Ilmu') }}</h1>
                <p class="text-slate-500 mt-4 text-lg">
                    {{ __('Kongsi majlis ilmu dengan komuniti. Penghantaran anda akan disemak sebelum diterbitkan.') }}
                </p>
            </div>

            {{--
            <div class="mb-8 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold text-slate-900">{{ __('Isi Automatik Dengan AI') }}</h2>
                <p class="mt-2 text-sm text-slate-600">
                    {{ __('Muat naik poster, gambar, atau PDF majlis. Kami akan cuba isi borang ini secara automatik dan bawa anda terus ke pratonton.') }}
                </p>

                <div class="mt-4 flex flex-col gap-3 md:flex-row md:items-center">
                    <label for="submit-event-source-attachment" class="sr-only">
                        {{ __('Pilih poster, gambar, atau PDF majlis') }}
                    </label>

                    <input id="submit-event-source-attachment" type="file" wire:model="event_source_attachment"
                        accept=".pdf,image/jpeg,image/png,image/webp"
                        aria-describedby="submit-event-source-attachment-help"
                        class="block w-full text-sm text-slate-700 file:mr-4 file:rounded-lg file:border-0 file:bg-slate-100 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-200">

                    <x-filament::button type="button" wire:click="extractEventFromMedia" wire:loading.attr="disabled"
                        wire:target="event_source_attachment,extractEventFromMedia" class="whitespace-nowrap">
                        {{ __('Ekstrak Dengan AI') }}
                    </x-filament::button>
                </div>

                @error('event_source_attachment')
                    <p class="mt-2 text-sm text-danger-600">{{ $message }}</p>
                @enderror

                <p id="submit-event-source-attachment-help" class="mt-2 text-xs text-slate-500">
                    {{ __('PDF, JPEG, PNG, atau WEBP dibenarkan. Kami akan cuba baca butiran majlis daripada fail ini.') }}
                </p>

                <p wire:loading wire:target="extractEventFromMedia" class="mt-2 text-sm text-primary-600">
                    {{ __('Sedang mengekstrak maklumat daripada fail...') }}
                </p>
            </div>
            --}}

            <form wire:submit="submit" novalidate>
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
