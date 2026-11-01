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

@include('partials.filament-assets', [
    'scripts' => ['filament/support', 'filament/schemas', 'filament/forms'],
])

<div class="min-h-screen bg-slate-50 pb-20">
    <div class="mx-auto w-full max-w-5xl px-3 py-6 sm:px-4 sm:py-8 lg:px-6 lg:py-10">
        <header class="rounded-3xl border border-emerald-900/10 bg-gradient-to-br from-emerald-900 via-emerald-800 to-emerald-950 px-6 py-8 text-white shadow-sm sm:px-8 sm:py-10">
            <p class="text-xs font-bold uppercase tracking-[0.24em] text-amber-300">{{ __('app-feedback.title') }}</p>
            <h1 class="mt-3 font-heading text-3xl font-bold tracking-tight sm:text-4xl">{{ __('app-feedback.title') }}</h1>
            <p class="mt-3 max-w-2xl text-sm leading-7 text-emerald-50/90 sm:text-base">{{ __('app-feedback.intro') }}</p>
            <div class="mt-5 flex flex-wrap gap-3 text-xs font-semibold">
                <a href="#maklum-balas-saya" class="inline-flex items-center rounded-full border border-white/20 bg-white/10 px-4 py-2 text-white transition hover:bg-white/15">
                    {{ __('app-feedback.history.heading') }}
                </a>
                <a href="{{ route('contact') }}" wire:navigate class="inline-flex items-center rounded-full border border-white/20 px-4 py-2 text-white/90 transition hover:bg-white/10">
                    {{ __('Contact Us') }}
                </a>
            </div>
        </header>

        @if (filled($successMessage))
            <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-900" role="status">
                {{ $successMessage }}
            </div>
        @endif

        @if ($canSubmit)
            <section class="mt-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7 lg:p-8">
                <form wire:submit="submit" class="space-y-6">
                    {{ $this->form }}

                    <div class="flex flex-col gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs leading-5 text-slate-500">{{ __('app-feedback.form.evidence_help') }}</p>
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

        <section id="maklum-balas-saya" class="mt-10 scroll-mt-24">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.22em] text-emerald-700">{{ __('app-feedback.history.heading') }}</p>
                    <h2 class="mt-2 font-heading text-2xl font-bold text-slate-900">{{ __('app-feedback.history.heading') }}</h2>
                </div>
                <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-600 shadow-sm">{{ $history->total() }}</span>
            </div>

            @if ($selected instanceof \App\Models\AppFeedback && ! $history->getCollection()->contains('id', $selected->id))
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
                                            <a href="{{ route('app-feedback.evidence.download', ['feedback' => $feedback, 'mediaId' => $media->getKey()]) }}"
                                                class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-800">
                                                {{ $media->name ?: $media->file_name }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
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
