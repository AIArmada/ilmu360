{{-- Shared parent-event info for the date and session pages. Expects $event, $detail, $languageLabels, $classificationLabels, $audienceLabels, $muslimOnly, $keyPeopleByRole, $organizer, $organizerHref, $galleryImages. --}}
@php
    $sharedAboutHtml = trim((string) $event->description_text) !== ''
        ? nl2br(e($event->description_text))
        : '';
    $sharedSummary = trim((string) ($event->summary ?? ''));
    $sharedSpeakers = $event->relationLoaded('persons') ? $event->persons : collect();
    $sharedChildFriendly = $event->audienceProfiles->first()?->is_child_friendly ?? (bool) $event->children_allowed;
    $sharedGenderLabel = $event->gender instanceof \BackedEnum ? $event->gender->getLabel() : null;
    $sharedAgeLabels = collect((array) ($event->age_group ?? []))
        ->map(fn (mixed $value): ?string => $value instanceof \BackedEnum
            ? $value->getLabel()
            : \App\Enums\EventAgeGroup::tryFrom((string) $value)?->getLabel())
        ->filter()
        ->values();
    $sharedMuslimOnly = (bool) ($muslimOnly ?? false);
    $sharedKeyPeople = $keyPeopleByRole ?? collect();
@endphp

@if($sharedSummary !== '' || $sharedAboutHtml !== '')
    <section class="rounded-[2rem] border border-slate-200 bg-white p-6 sm:p-8" aria-labelledby="shared-event-about-title">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-emerald-900">{{ __('Maklumat majlis') }}</p>
        <h2 id="shared-event-about-title" class="mt-2 font-heading text-2xl font-bold text-emerald-950">{{ __('Tentang :title', ['title' => $event->title]) }}</h2>
        @if($sharedSummary !== '')<p class="mt-3 text-sm font-semibold leading-7 text-slate-700">{{ $sharedSummary }}</p>@endif
        @if($sharedAboutHtml !== '')<div class="mt-3 text-sm leading-7 text-slate-700">{!! $sharedAboutHtml !!}</div>@endif
    </section>
@endif

@if($classificationLabels->isNotEmpty())
    <section class="rounded-[2rem] border border-slate-200 bg-white p-6 sm:p-8" aria-labelledby="shared-event-classification-title">
        <h2 id="shared-event-classification-title" class="font-heading text-2xl font-bold text-emerald-950">{{ __('Klasifikasi') }}</h2>
        <div class="mt-4 flex flex-wrap gap-2">
            @foreach($classificationLabels as $classificationLabel)
                <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ $classificationLabel }}</span>
            @endforeach
        </div>
    </section>
@endif

@if($languageLabels->isNotEmpty() || $audienceLabels->isNotEmpty() || $sharedGenderLabel || $sharedAgeLabels->isNotEmpty() || $sharedMuslimOnly)
    <section class="rounded-[2rem] border border-slate-200 bg-white p-6 sm:p-8" aria-labelledby="shared-event-audience-title">
        <h2 id="shared-event-audience-title" class="font-heading text-2xl font-bold text-emerald-950">{{ __('Peserta & bahasa') }}</h2>
        <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            @if($languageLabels->isNotEmpty())
                <div><dt class="font-semibold text-slate-500">{{ __('Bahasa') }}</dt><dd class="mt-1 font-semibold text-slate-900">{{ $languageLabels->implode(', ') }}</dd></div>
            @endif
            @if($sharedGenderLabel)
                <div><dt class="font-semibold text-slate-500">{{ __('Kehadiran') }}</dt><dd class="mt-1 text-slate-700">{{ $sharedGenderLabel }}</dd></div>
            @endif
            @if($sharedAgeLabels->isNotEmpty())
                <div><dt class="font-semibold text-slate-500">{{ __('Peringkat umur') }}</dt><dd class="mt-1 text-slate-700">{{ $sharedAgeLabels->implode(', ') }}</dd></div>
            @endif
            @if($audienceLabels->isNotEmpty())
                <div class="sm:col-span-2"><dt class="font-semibold text-slate-500">{{ __('Sasaran') }}</dt><dd class="mt-2 flex flex-wrap gap-2">@foreach($audienceLabels as $audienceLabel)<span class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700">{{ $audienceLabel }}</span>@endforeach</dd></div>
            @endif
        </dl>
        @if($sharedMuslimOnly)
            <p class="mt-4 rounded-xl bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-900">{{ __('Muslim sahaja') }}</p>
        @endif
        @if($sharedChildFriendly)
            <p class="mt-4 rounded-xl bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-900">{{ __('Mesra kanak-kanak') }}</p>
        @endif
    </section>
@endif

@if($organizer)
    <section class="rounded-[2rem] border border-slate-200 bg-white p-6 sm:p-8" aria-labelledby="shared-event-organizer-title">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-emerald-900">{{ __('Penganjur') }}</p>
        <h2 id="shared-event-organizer-title" class="mt-2 font-heading text-2xl font-bold text-emerald-950">{{ __('Penganjur majlis') }}</h2>
        @if($organizerHref)
            <a href="{{ $organizerHref }}" wire:navigate class="mt-3 block font-heading text-xl font-semibold text-emerald-900 hover:underline">{{ $organizer->name }}</a>
        @else
            <p class="mt-3 font-heading text-xl font-semibold text-emerald-900">{{ $organizer->name }}</p>
        @endif
    </section>
@endif

@if($sharedSpeakers->isNotEmpty())
    <section class="rounded-[2rem] border border-slate-200 bg-white p-6 sm:p-8" aria-labelledby="shared-event-speakers-title">
        <h2 id="shared-event-speakers-title" class="font-heading text-2xl font-bold text-emerald-950">{{ __('Penceramah majlis') }}</h2>
        <ul class="mt-4 grid gap-3 sm:grid-cols-2">
            @foreach($sharedSpeakers as $speaker)
                <li>
                    <a href="{{ route('persons.show', $speaker) }}" wire:navigate class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-[#fbfaf7] p-4 transition hover:border-emerald-200 hover:bg-emerald-50">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-sm font-bold text-emerald-900" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) ($speaker->name ?? '?'), 0, 1)) }}</span>
                        <span class="min-w-0">
                            <span class="block truncate font-semibold text-slate-900">{{ $speaker->name }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif

@if($sharedKeyPeople->isNotEmpty())
    <section class="rounded-[2rem] border border-slate-200 bg-white p-6 sm:p-8" aria-labelledby="shared-event-roles-title">
        <h2 id="shared-event-roles-title" class="font-heading text-2xl font-bold text-emerald-950">{{ __('Peranan') }}</h2>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            @foreach($sharedKeyPeople as $role => $keyPeople)
                @php($roleLabel = \App\Enums\EventKeyPersonRole::tryFrom((string) $role)?->getLabel() ?? \Illuminate\Support\Str::headline((string) $role))
                <div class="rounded-2xl border border-slate-200 bg-[#fbfaf7] p-4">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-900">{{ $roleLabel }}</p>
                    <div class="mt-3 space-y-3">
                        @foreach($keyPeople as $keyPerson)
                            @if($keyPerson->person)
                                <a href="{{ route('persons.show', $keyPerson->person) }}" wire:navigate class="flex items-center gap-3 font-semibold text-emerald-950 hover:underline">
                                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-sm font-bold text-emerald-900" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $keyPerson->person->name, 0, 1)) }}</span>
                                    <span class="min-w-0 truncate">{{ $keyPerson->person->name }}</span>
                                </a>
                            @elseif(filled($keyPerson->display_name))
                                <span class="flex items-center gap-3 font-semibold text-slate-800">
                                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-slate-200 text-sm font-bold text-slate-700" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $keyPerson->display_name, 0, 1)) }}</span>
                                    <span class="min-w-0 truncate">{{ $keyPerson->display_name }}</span>
                                </span>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endif

@if($galleryImages !== [])
    <section class="rounded-[2rem] border border-slate-200 bg-white p-6 sm:p-8" aria-labelledby="shared-event-gallery-title">
        <h2 id="shared-event-gallery-title" class="font-heading text-2xl font-bold text-emerald-950">{{ __('Galeri') }}</h2>
        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
            @foreach($galleryImages as $galleryImage)
                <a href="{{ $galleryImage['url'] }}" target="_blank" rel="noopener" class="overflow-hidden rounded-xl border border-slate-200">
                    <img src="{{ $galleryImage['thumb'] }}" alt="{{ $galleryImage['alt'] }}" class="aspect-square size-full object-cover" loading="lazy">
                </a>
            @endforeach
        </div>
    </section>
@endif
