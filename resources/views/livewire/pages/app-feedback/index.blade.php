@php
    $history = $this->history;
    $selected = $this->selectedFeedback;

    $badgeClass = static fn (string $color): string => match ($color) {
        'danger' => 'bg-rose-100 text-rose-700',
        'success' => 'bg-emerald-100 text-emerald-700',
        'info' => 'bg-sky-100 text-sky-700',
        'warning' => 'bg-amber-100 text-amber-800',
        default => 'bg-slate-100 text-slate-700',
    };
@endphp

@section('title', __('app-feedback.title').' - '.config('app.name'))
@section('og_image', asset('images/app-feedback/hero-desktop-v2.png'))
@section('og_image_alt', __('app-feedback.title'))
@section('og_image_width', '1672')
@section('og_image_height', '941')

@include('partials.filament-assets', [
    'scripts' => ['filament/support', 'filament/schemas', 'filament/forms'],
])

<div data-art-direction="living-majlis" class="living-majlis-field relative min-h-screen overflow-x-clip text-slate-800">
    <div class="relative isolate overflow-hidden border-b border-emerald-900/[0.06]">
        <div data-material="hero-field" class="absolute inset-0 overflow-hidden bg-[#f7f3e8]">
            <picture class="absolute inset-0">
                <source media="(max-width: 639px)" srcset="{{ asset('images/app-feedback/hero-mobile-v3.webp') }}" type="image/webp">
                <img
                    srcset="{{ asset('images/app-feedback/hero-desktop-v2.webp') }}"
                    src="{{ asset('images/app-feedback/hero-desktop-v2.png') }}"
                    alt=""
                    aria-hidden="true"
                    class="absolute inset-0 h-full w-full object-cover object-center sm:object-[42%_center] lg:object-center"
                    width="1672"
                    height="941"
                    loading="eager"
                    fetchpriority="high"
                    decoding="async"
                >
            </picture>
            <div class="absolute inset-0 bg-gradient-to-r from-[#fffdf8]/78 via-[#fffdf8]/24 to-transparent"></div>
            <div class="absolute inset-0 sm:hidden" style="background: linear-gradient(90deg, rgba(255,253,248,.97) 0%, rgba(255,253,248,.92) 50%, rgba(255,253,248,.65) 78%, rgba(255,253,248,.28) 94%, rgba(255,253,248,.12) 100%);"></div>
        </div>

        <div class="relative mx-auto max-w-6xl px-5 pb-10 pt-48 sm:px-8 sm:pb-12 sm:pt-52 lg:px-10 lg:pt-52">
            <div class="relative z-10 max-w-2xl">
                <h1 class="font-heading text-4xl font-semibold leading-[1.04] tracking-[-0.035em] text-emerald-950 sm:text-5xl">
                    {{ __('app-feedback.title') }}
                </h1>
                <p class="mt-5 max-w-xl text-base leading-8 text-slate-700 sm:text-lg">
                    {{ __('app-feedback.intro') }}
                </p>
                <div class="mt-8 flex flex-wrap items-center gap-3">
                    @if ($canSubmit)
                        <a href="#hantar-maklum-balas" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-emerald-800 px-5 text-sm font-bold text-white shadow-[0_14px_28px_-18px_rgba(6,78,59,0.70)] transition-all duration-200 hover:-translate-y-0.5 hover:bg-emerald-700 hover:shadow-[0_20px_36px_-18px_rgba(6,78,59,0.75)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/15">
                            {{ __('app-feedback.form.heading') }}
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5 5 5-5 5" />
                            </svg>
                        </a>
                    @endif
                    <a href="#maklum-balas-saya" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-emerald-200 bg-white/80 px-5 text-sm font-bold text-emerald-700 shadow-sm backdrop-blur-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-emerald-300 hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/10">
                        {{ __('app-feedback.history.heading') }}
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="mx-auto w-full max-w-5xl px-3 pb-20 pt-8 sm:px-4 sm:pt-10 lg:px-6 lg:pt-12">

        @if (filled($successMessage))
            <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-900" role="status">
                {{ $successMessage }}
            </div>
        @endif

        @if ($canSubmit)
            <section id="hantar-maklum-balas" class="mt-8 scroll-mt-24 sm:mt-10">
                <form
                    wire:submit="submit"
                    class="space-y-6"
                    x-data="{
                        init() {
                            try {
                                const saved = sessionStorage.getItem('app-feedback-draft');
                                if (saved && ! this.$wire.get('data.title')) {
                                    const draft = JSON.parse(saved);
                                    if (draft && typeof draft === 'object') {
                                        for (const key of ['title', 'description']) {
                                            if (typeof draft[key] === 'string') {
                                                this.$wire.set(`data.${key}`, draft[key], false);
                                            }
                                        }
                                        if (draft.details && typeof draft.details === 'object') {
                                            for (const key of ['steps', 'expected', 'actual', 'problem', 'suggestion']) {
                                                if (typeof draft.details[key] === 'string') {
                                                    this.$wire.set(`data.details.${key}`, draft.details[key], false);
                                                }
                                            }
                                        }
                                        if (typeof draft.category === 'string') {
                                            this.$wire.set('data.category', draft.category);
                                        }
                                    }
                                }
                            } catch (error) { /* Draft storage may be unavailable or corrupt. */ }
                            this.$watch('$wire.data', () => this.save());
                        },
                        save() {
                            try {
                                const draft = {
                                    category: this.$wire.get('data.category'),
                                    title: this.$wire.get('data.title') ?? '',
                                    description: this.$wire.get('data.description') ?? '',
                                    details: this.$wire.get('data.details') ?? {},
                                };
                                if (! draft.title && ! draft.description && ! Object.values(draft.details).some(Boolean)) {
                                    this.clear();
                                    return;
                                }
                                sessionStorage.setItem('app-feedback-draft', JSON.stringify(draft));
                            } catch (error) { /* Draft storage may be unavailable. */ }
                        },
                        clear() {
                            try { sessionStorage.removeItem('app-feedback-draft'); } catch (error) { /* Storage unavailable. */ }
                        },
                    }"
                    @app-feedback-submitted.window="clear()"
                >
                    {{ $this->form }}

                    @if (filled($formError))
                        <div class="rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-medium text-rose-900" role="alert">
                            {{ $formError }}
                        </div>
                    @endif

                    <div class="flex flex-col gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:items-center sm:justify-end">
                        <button
                            type="submit"
                            class="inline-flex w-full items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-800 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
                            wire:loading.attr="disabled"
                            wire:target="submit"
                        >
                            <span wire:loading.remove wire:target="submit">{{ __('app-feedback.form.submit') }}</span>
                            <span wire:loading wire:target="submit">{{ __('app-feedback.form.submitting') }}</span>
                        </button>
                    </div>
                </form>
            </section>
        @else
            <section class="mt-6 rounded-3xl border border-amber-200 bg-amber-50 p-6 text-sm leading-6 text-amber-900">
                <p class="font-semibold">{{ __('app-feedback.flash.blocked') }}</p>
                <p class="mt-2">{{ __('app-feedback.history.empty') }}</p>
                <a href="{{ route('contact') }}" wire:navigate class="mt-4 inline-flex font-semibold underline underline-offset-4">{{ __('Contact Us') }}</a>
            </section>
        @endif

        <section id="maklum-balas-saya" class="mt-14 scroll-mt-24 border-t border-slate-200 pt-8 sm:mt-16 sm:pt-10">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.22em] text-emerald-700">{{ __('app-feedback.history.heading') }}</p>
                    <h2 class="mt-2 font-heading text-2xl font-bold text-slate-900">{{ __('app-feedback.history.heading') }}</h2>
                </div>
                <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-600 shadow-sm">{{ $history->total() }}</span>
            </div>

            @if ($selected instanceof \App\Features\Feedback\Models\AppFeedback && ! $history->getCollection()->contains('id', $selected->id))
                <article id="maklum-balas-{{ $selected->id }}" class="mt-6 scroll-mt-24 rounded-2xl border border-emerald-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-emerald-700">{{ __('app-feedback.history.view') }}</p>
                    <h3 class="mt-2 text-lg font-semibold text-slate-900">{{ $selected->title }}</h3>
                    <p class="mt-1 text-xs text-slate-500">{{ \App\Support\Timezone\UserDateTimeFormatter::format($selected->created_at, 'd M Y, h:i A') }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClass($selected->category->getColor()) }}">{{ $selected->category->getLabel() }}</span>
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClass($selected->status->getColor()) }}">{{ $selected->status->getLabel() }}</span>
                    </div>
                    <p class="mt-4 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $selected->description }}</p>
                    @if (filled($selected->resolution_note))
                        <div class="mt-4 rounded-xl border border-emerald-100 bg-emerald-50 p-4">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('app-feedback.history.resolution') }}</p>
                            <p class="mt-2 whitespace-pre-line text-sm leading-6 text-emerald-950">{{ $selected->resolution_note }}</p>
                        </div>
                    @endif
                    @include('livewire.pages.app-feedback.partials.thread', ['feedback' => $selected])
                </article>
            @endif

            <div class="mt-6 space-y-4">
                @forelse ($history as $feedback)
                    <article
                        id="maklum-balas-{{ $feedback->id }}"
                        wire:key="app-feedback-{{ $feedback->id }}"
                        class="scroll-mt-24 rounded-2xl border bg-white p-5 shadow-sm transition sm:p-6 {{ $selected?->id === $feedback->id ? 'border-emerald-400 ring-2 ring-emerald-200' : 'border-slate-200' }}"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap gap-2">
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClass($feedback->category->getColor()) }}">{{ $feedback->category->getLabel() }}</span>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClass($feedback->status->getColor()) }}">{{ $feedback->status->getLabel() }}</span>
                                </div>
                                <h3 class="mt-3 break-words text-base font-semibold text-slate-900 sm:text-lg">{{ $feedback->title }}</h3>
                                <p class="mt-1 text-xs text-slate-500">{{ \App\Support\Timezone\UserDateTimeFormatter::format($feedback->created_at, 'd M Y, h:i A') }}</p>
                            </div>
                            <a href="{{ route('app-feedback.index', ['lihat' => $feedback->id]) }}#maklum-balas-{{ $feedback->id }}"
                                wire:navigate
                                class="inline-flex shrink-0 items-center rounded-full border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-800">
                                {{ __('app-feedback.history.view') }}
                            </a>
                        </div>

                        <p class="mt-4 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $feedback->description }}</p>

                        @if (filled($feedback->page_url))
                            <p class="mt-3 text-xs text-slate-500">
                                <span class="font-semibold text-slate-600">{{ __('app-feedback.form.page_url') }}:</span>
                                <a href="{{ $feedback->page_url }}" target="_blank" rel="noopener noreferrer" class="underline underline-offset-2 hover:text-emerald-800">{{ $feedback->page_url }}</a>
                            </p>
                        @endif

                        @if (filled($feedback->resolution_note))
                            <div class="mt-4 rounded-xl border border-emerald-100 bg-emerald-50 p-4">
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ __('app-feedback.history.resolution') }}</p>
                                <p class="mt-2 whitespace-pre-line text-sm leading-6 text-emerald-950">{{ $feedback->resolution_note }}</p>
                            </div>
                        @endif

                        @php
                            $evidence = $feedback->media->where('collection_name', 'evidence');
                        @endphp

                        @if ($evidence->isNotEmpty())
                            <div class="mt-4 border-t border-slate-100 pt-4">
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">{{ __('app-feedback.history.attachments') }}</p>
                                <ul class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($evidence as $media)
                                        <li wire:key="app-feedback-evidence-{{ $feedback->id }}-{{ $media->getKey() }}">
                                            @if (str_starts_with((string) $media->mime_type, 'image/'))
                                                <a href="{{ route('app-feedback.evidence.download', ['feedback' => $feedback, 'mediaId' => $media->getKey(), 'preview' => 1]) }}"
                                                    target="_blank" rel="noopener noreferrer"
                                                    class="block overflow-hidden rounded-xl border border-slate-200 transition hover:border-emerald-300">
                                                    <img src="{{ route('app-feedback.evidence.download', ['feedback' => $feedback, 'mediaId' => $media->getKey(), 'preview' => 1]) }}"
                                                        alt="{{ $media->name ?: $media->file_name }}"
                                                        class="h-24 w-24 object-cover" loading="lazy" />
                                                </a>
                                            @else
                                                <a href="{{ route('app-feedback.evidence.download', ['feedback' => $feedback, 'mediaId' => $media->getKey()]) }}"
                                                    class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-800">
                                                    {{ $media->name ?: $media->file_name }}
                                                </a>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        @include('livewire.pages.app-feedback.partials.thread', ['feedback' => $feedback])
                    </article>
                @empty
                    <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center">
                        <p class="text-sm text-slate-500">{{ __('app-feedback.history.empty') }}</p>
                    </div>
                @endforelse
            </div>

            <div class="mt-6">
                {{ $history->links() }}
            </div>
        </section>
    </div>
</div>
