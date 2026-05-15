@section('title', $event->title . ' - ' . config('app.name'))
@section('meta_description', Str::limit($event->description_text !== '' ? $event->description_text : __('Lihat siri program ini dan senarai majlis di bawahnya di :app.', ['app' => config('app.name')]), 160))
@section('meta_robots', $this->metaRobots)
@section('og_url', route('events.show', $event))
@section('og_image', $event->card_image_url)
@section('og_image_alt', __('Poster untuk :title', ['title' => $event->title]))

@push('head')
    <link rel="canonical" href="{{ route('events.show', $event) }}">
@endpush

@php
    $publicChildEvents = $this->publicChildEvents;
    $parentProgramManagementLinks = $this->parentProgramManagementLinks;
    $primaryInstitution = $event->institution;
    $coverImage = $primaryInstitution?->getFirstMediaUrl('cover', 'banner') ?: $event->card_image_url;
@endphp

<div class="min-h-screen bg-slate-50 pb-24">
    <section class="relative overflow-hidden bg-slate-950 text-white">
        <div class="absolute inset-0">
            @if($coverImage)
                <img src="{{ $coverImage }}" alt="" class="size-full object-cover opacity-30" aria-hidden="true">
            @endif
            <div class="absolute inset-0 bg-gradient-to-br from-emerald-950/95 via-slate-950/90 to-cyan-950/95"></div>
        </div>

        <div class="relative mx-auto flex max-w-6xl flex-col gap-8 px-6 py-16 lg:px-12 lg:py-24">
            <div class="max-w-4xl space-y-4">
                <span class="inline-flex rounded-full border border-white/20 bg-white/10 px-4 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-white/80">{{ __('Parent Program') }}</span>
                <h1 class="font-heading text-4xl font-bold tracking-tight text-white md:text-5xl">{{ $event->title }}</h1>
                @if($event->description_text !== '')
                    <p class="max-w-3xl text-base leading-7 text-white/80 md:text-lg">{{ $event->description_text }}</p>
                @endif
                @if($parentProgramManagementLinks)
                    <div class="flex flex-wrap items-center gap-3 pt-2">
                        <a href="{{ $parentProgramManagementLinks['create_child_url'] }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-white/20 bg-white/10 px-5 py-3 text-sm font-semibold text-white transition hover:bg-white/15">
                            {{ __('Create Child Event') }}
                        </a>
                    </div>
                @endif
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-2xl border border-white/10 bg-white/10 p-5 backdrop-blur-sm">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-white/60">{{ __('Program Window') }}</p>
                    <p class="mt-2 text-sm text-white">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->starts_at, 'd M Y') }} - {{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($event->ends_at, 'd M Y') }}</p>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/10 p-5 backdrop-blur-sm">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-white/60">{{ __('Organizer') }}</p>
                    <p class="mt-2 text-sm text-white">{{ $event->organizer?->name ?? $event->institution?->name ?? __('TBC') }}</p>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/10 p-5 backdrop-blur-sm">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-white/60">{{ __('Child Events') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-white">{{ $publicChildEvents->count() }}</p>
                </div>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-6 pt-10 lg:px-12">
        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm md:p-8">
            <div class="flex items-end justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-bold text-slate-900">{{ __('Program Schedule') }}</h2>
                    <p class="mt-2 text-sm text-slate-500">{{ __('Each card below is a first-class child event with its own public page.') }}</p>
                </div>
            </div>

            <div class="mt-6 space-y-4">
                @forelse($publicChildEvents as $childEvent)
                    @php
                        $childLocation = $childEvent->venue?->name ?? $childEvent->institution?->name ?? __('Location TBC');
                    @endphp
                    <a href="{{ route('events.show', $childEvent) }}" wire:navigate class="group block rounded-2xl border border-slate-200 bg-slate-50 p-5 transition hover:border-emerald-300 hover:bg-emerald-50/50">
                        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                            <div class="space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">{{ $childEvent->event_type instanceof \Illuminate\Support\Collection ? $childEvent->event_type->first()?->getLabel() : __('Event') }}</span>
                                    <span class="rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700">{{ $childEvent->event_format?->label() ?? __('Format') }}</span>
                                </div>
                                <h3 class="text-xl font-semibold text-slate-900 transition group-hover:text-emerald-800">{{ $childEvent->title }}</h3>
                                @if($childEvent->description_text !== '')
                                    <p class="max-w-3xl text-sm leading-6 text-slate-600">{{ Str::limit($childEvent->description_text, 180) }}</p>
                                @endif
                            </div>
                            <div class="shrink-0 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 shadow-sm">
                                <p class="font-semibold text-slate-900">{{ \App\Support\Timezone\UserDateTimeFormatter::translatedFormat($childEvent->starts_at, 'd M Y') }}</p>
                                <p>{{ \App\Support\Timezone\UserDateTimeFormatter::format($childEvent->starts_at, 'h:i A') }}</p>
                                <p class="mt-2 text-xs text-slate-500">{{ $childLocation }}</p>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-sm text-slate-500">
                        <p>{{ __('No public child events are available for this program yet.') }}</p>

                        @if($parentProgramManagementLinks)
                            <div class="mt-5 flex flex-col items-center justify-center gap-3 sm:flex-row">
                                <a href="{{ $parentProgramManagementLinks['create_child_url'] }}" wire:navigate class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700">
                                    {{ __('Create First Child Event') }}
                                </a>
                                <a href="{{ $parentProgramManagementLinks['ahli_url'] }}" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">
                                    {{ __('Manage in Ahli Panel') }}
                                </a>
                            </div>
                        @endif
                    </div>
                @endforelse
            </div>
        </div>
    </section>
</div>