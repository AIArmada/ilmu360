{{-- Shared parent-event info for the date and session pages. Expects $event, $detail, $languageLabels, $audienceLabels, $muslimOnly, $keyPeopleByRole, $organizer, $organizerHref, $galleryImages. --}}
@php
    $occurrenceDescription = $occurrence?->metadata['description_localized'] ?? null;
    $occurrenceDescriptionText = null;
    if (is_array($occurrenceDescription)) {
        foreach ([app()->getLocale(), config('app.fallback_locale')] as $descriptionLocale) {
            if (is_string($descriptionLocale) && is_string($occurrenceDescription[$descriptionLocale] ?? null) && trim($occurrenceDescription[$descriptionLocale]) !== '') {
                $occurrenceDescriptionText = $occurrenceDescription[$descriptionLocale];
                break;
            }
        }
        if ($occurrenceDescriptionText === null) {
            foreach ($occurrenceDescription as $descriptionValue) {
                if (is_string($descriptionValue) && trim($descriptionValue) !== '') {
                    $occurrenceDescriptionText = $descriptionValue;
                    break;
                }
            }
        }
    }
    $sharedDescription = $occurrenceDescriptionText ?? $event->description_text;
    $sharedAboutHtml = trim((string) $sharedDescription) !== ''
        ? nl2br(e($sharedDescription))
        : '';
    $sharedSummary = trim((string) ($event->summary ?? ''));
    $hasOccurrenceContext = $occurrence instanceof \AIArmada\Events\Models\EventOccurrence;
    $occurrenceOwnsSubmissionContent = $hasOccurrenceContext
        && is_array($occurrence->metadata['public_submission'] ?? null);
    $occurrenceAudiences = $occurrenceOwnsSubmissionContent && $occurrence->relationLoaded('audiences')
        ? $occurrence->audiences
        : $event->audiences;
    $occurrenceAgeValues = $occurrenceOwnsSubmissionContent
        ? $occurrenceAudiences->where('audience_type', 'age_group')->pluck('value')->all()
        : (array) ($event->age_group ?? []);
    $occurrenceGenderValue = $occurrenceAudiences->firstWhere('audience_type', 'gender')?->value;
    $occurrenceGender = $occurrenceOwnsSubmissionContent
        ? \App\Shared\Events\Enums\EventGenderRestriction::tryFrom((string) $occurrenceGenderValue)
        : ($genderRestriction ?? $event->gender);
    $audienceLabels = $occurrenceOwnsSubmissionContent
        ? $occurrenceAudiences
            ->reject(fn ($audience): bool => in_array($audience->audience_type, ['gender', 'age_group', 'religion'], true))
            ->map(fn ($audience): ?string => filled($audience->value) ? (string) $audience->value : null)
            ->filter()
            ->unique()
            ->values()
        : ($audienceLabels ?? collect());
    $sharedSpeakers = $occurrenceOwnsSubmissionContent ? collect() : ($event->relationLoaded('persons') ? $event->persons : collect());
    $sharedChildFriendly = $occurrenceOwnsSubmissionContent
        ? (bool) ($occurrence->relationLoaded('audienceProfiles') ? $occurrence->audienceProfiles->first()?->is_child_friendly : false)
        : ($event->audienceProfiles->first()?->is_child_friendly ?? (bool) $event->children_allowed);
    $sharedAgeLabels = collect($occurrenceAgeValues)
        ->map(fn (mixed $value): ?string => $value instanceof \BackedEnum
            ? $value->getLabel()
            : \App\Shared\Events\Enums\EventAgeGroup::tryFrom((string) $value)?->getLabel())
        ->filter()
        ->values();
    $sharedMuslimOnly = $occurrenceOwnsSubmissionContent
        ? $occurrenceAudiences->contains(fn ($audience): bool => $audience->audience_type === 'religion' && $audience->value === 'muslim_only')
        : (bool) ($muslimOnly ?? false);
    $sharedKeyPeople = $occurrenceOwnsSubmissionContent ? collect() : ($keyPeopleByRole ?? collect());

    // Attendance is a welcome, not a spec sheet: one soft card of tinted
    // pills states who the majlis is for without reading like a policy table.
    $sharedGenderChipLabel = match ($occurrenceGender) {
        \App\Shared\Events\Enums\EventGenderRestriction::WomenOnly => __('Women'),
        \App\Shared\Events\Enums\EventGenderRestriction::MenOnly => __('Men'),
        default => null,
    };
    $sharedDetailIcons = [
        'heart' => 'M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z',
        'moon' => 'M21.752 15.002A9.72 9.72 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z',
        'smile' => 'M15.182 15.182a4.5 4.5 0 01-6.364 0M21 12a9 9 0 11-18 0 9 9 0 0118 0zM9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75s.168-.75.375-.75.375.336.375.75zm-.375 0h.008v.015h-.008V9.75zm5.625 0c0 .414-.168.75-.375.75s-.375-.336-.375-.75.168-.75.375-.75.375.336.375.75zm-.375 0h.008v.015h-.008V9.75z',
        'language' => 'M10.5 21l5.25-11.25L21 21m-9-3h7.5M3 5.621a48.474 48.474 0 016-.371m0 0c1.12 0 2.233.038 3.334.114M9 5.25V3m3.334 2.364C11.176 10.658 7.69 15.08 3 17.502m9.334-12.138c.896.061 1.785.147 2.666.257m-4.589 8.495a18.023 18.023 0 01-3.827-5.802',
        'people' => 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z',
        'target' => 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z',
    ];

    $sharedAttendanceChips = collect([
        filled($sharedGenderChipLabel) ? [
            'icon' => 'people',
            'prefix' => null,
            'value' => $sharedGenderChipLabel,
            'tone' => 'border-[#e7c9c4]/70 bg-[#fbf0ee] text-[#7c3a33]',
            'iconTone' => 'text-[#a8544a]',
        ] : null,
        $sharedMuslimOnly ? [
            'icon' => 'moon',
            'prefix' => null,
            'value' => __('Muslim'),
            'tone' => 'border-[#cfe0d5] bg-[#eef4ee] text-[#1c4636]',
            'iconTone' => 'text-[#2f5d43]',
        ] : null,
        $sharedAgeLabels->isNotEmpty() ? [
            'icon' => 'smile',
            'prefix' => null,
            'value' => $sharedAgeLabels->implode(', '),
            'tone' => 'border-[#cfe0e8] bg-[#eef5f9] text-[#21506a]',
            'iconTone' => 'text-[#2c6e91]',
        ] : null,
        $languageLabels->isNotEmpty() ? [
            'icon' => 'language',
            'prefix' => __('Bahasa'),
            'value' => $languageLabels->implode(', '),
            'tone' => 'border-[#e6dccb] bg-[#f8f3e8] text-[#4c5b56]',
            'iconTone' => 'text-[#8a7f72]',
        ] : null,
        $audienceLabels->isNotEmpty() ? [
            'icon' => 'target',
            'prefix' => __('Sasaran'),
            'value' => $audienceLabels->implode(' · '),
            'tone' => 'border-[#ddd3ea] bg-[#f3eff8] text-[#4f3d6b]',
            'iconTone' => 'text-[#7c5fa8]',
        ] : null,
    ])->filter()->values();

    // A drop cap only works on a paragraph long enough to wrap around it. On a
    // one-line description it is decoration, so short copy becomes a lead line.
    $sharedAboutIsLong = mb_strlen(trim(strip_tags((string) $sharedDescription))) > 320;

    $sharedHasDetailRows = $sharedAttendanceChips->isNotEmpty();
@endphp

@if($sharedSummary !== '' || $sharedAboutHtml !== '')
    <section id="tentang" class="scroll-mt-8" aria-labelledby="shared-event-about-title">
        <div class="mb-7">
            <h2 id="shared-event-about-title" class="font-heading text-[1.75rem] leading-[1.15] tracking-[-0.01em] text-balance">{{ __('Tentang majlis') }}</h2>
        </div>

        {{-- Prose sits on the page, not in a card: a bordered box around one
             short paragraph reads as an empty container, not as composition.
             Short copy is set as a lead line; only a paragraph long enough to
             wrap gets the drop cap. --}}
        <div>
            @if($sharedSummary !== '')
                <p class="max-w-[50ch] font-heading text-[1.625rem] leading-[1.45] tracking-[-0.015em] text-balance text-[#14342e]">{{ $sharedSummary }}</p>
            @endif
            @if($sharedAboutHtml !== '')
                @if($sharedAboutIsLong)
                    <div class="{{ $sharedSummary !== '' ? 'mt-6' : '' }} mi-prose mi-dropcap">{!! $sharedAboutHtml !!}</div>
                @else
                    <p class="{{ $sharedSummary !== '' ? 'mt-5' : '' }} max-w-[46ch] font-heading text-[1.375rem] leading-[1.5] tracking-[-0.012em] text-balance text-[#14342e]">{!! $sharedAboutHtml !!}</p>
                @endif
            @endif
        </div>
    </section>
@endif

@if($sharedHasDetailRows)
    <section id="butiran" class="scroll-mt-8" aria-labelledby="shared-event-details-title">
        <div class="mb-7">
            <h2 id="shared-event-details-title" class="font-heading text-[1.75rem] leading-[1.15] tracking-[-0.01em] text-balance">{{ __('Who this majlis welcomes') }}</h2>
            <p class="mt-2 max-w-[48ch] text-[14px] leading-6 text-[#6b645e]">{{ __('Everyone listed here is welcome to attend.') }}</p>
        </div>

        <div class="mi-paper rounded-[20px] border border-[#e6dccb] bg-[#fffdf9] p-5 sm:p-6">
            <ul class="flex flex-wrap gap-2.5">
                @foreach($sharedAttendanceChips as $chip)
                    <li class="inline-flex items-start gap-2.5 rounded-2xl border px-4 py-2.5 sm:items-center {{ $chip['tone'] }}">
                        <svg class="mt-0.5 size-4 shrink-0 sm:mt-0 {{ $chip['iconTone'] }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $sharedDetailIcons[$chip['icon']] ?? '' }}" />
                        </svg>
                        <span class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                            @if(filled($chip['prefix']))
                                <span class="text-[10px] font-semibold uppercase tracking-[0.14em] opacity-70">{{ $chip['prefix'] }}</span>
                            @endif
                            <span class="text-[13px] font-semibold leading-snug">{{ $chip['value'] }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>

            {{-- Child-friendliness is the room's atmosphere, not a gate on
                 who may attend, so it sits apart from the attendance pills. --}}
            @if($sharedChildFriendly)
                <div class="mt-4 flex items-start gap-3 rounded-2xl border border-[#ecd9a9]/70 bg-[#fcf7ea] px-4 py-3">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-[#f2c867]/25 text-[#a3730f]">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $sharedDetailIcons['heart'] }}" />
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-[#8a5f13]">{{ __('Suasana') }}</p>
                        <p class="mt-0.5 text-[13px] font-medium leading-5 text-[#4c5b56]">{{ __('Persekitaran mesra kanak-kanak') }}</p>
                    </div>
                </div>
            @endif
        </div>
    </section>
@endif

@if($organizer && ! ($hideOrganizer ?? false))
    <section id="penganjur" class="scroll-mt-8" aria-labelledby="shared-event-organizer-title">
        <div class="mb-7">
            <span class="block h-[2px] w-7 bg-[#c9962f]" aria-hidden="true"></span>
            <p class="mt-4 text-[11px] font-semibold uppercase tracking-[0.26em] text-[#8a5f13]">{{ __('Penganjur') }}</p>
            <h2 id="shared-event-organizer-title" class="mt-2 font-heading text-[1.75rem] leading-[1.15] tracking-[-0.01em] text-balance">{{ __('Penganjur majlis') }}</h2>
        </div>

        <div class="mi-paper rounded-[20px] border border-[#e6dccb] bg-[#fffdf9]">
            @if($organizerHref)
                <a href="{{ $organizerHref }}" wire:navigate class="group flex items-center gap-4 px-6 py-5 transition-colors duration-300 hover:bg-[#faf6ed] focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[#14342e]">
                    <span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-[#14342e] font-heading text-lg text-[#f2c867]" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $organizer->name, 0, 1)) }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-heading text-lg leading-snug text-[#14342e]">{{ $organizer->name }}</span>
                        <span class="mt-0.5 block text-[13px] text-[#6b645e]">{{ __('Menganjurkan majlis ini') }}</span>
                    </span>
                    <svg class="size-4 shrink-0 text-[#c9bfae] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] group-hover:translate-x-0.5 group-hover:text-[#c9962f]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6" /></svg>
                </a>
            @else
                <div class="flex items-center gap-4 px-6 py-5">
                    <span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-[#14342e] font-heading text-lg text-[#f2c867]" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $organizer->name, 0, 1)) }}</span>
                    <span class="min-w-0">
                        <span class="block truncate font-heading text-lg leading-snug text-[#14342e]">{{ $organizer->name }}</span>
                        <span class="mt-0.5 block text-[13px] text-[#6b645e]">{{ __('Menganjurkan majlis ini') }}</span>
                    </span>
                </div>
            @endif
        </div>
    </section>
@endif

@if($sharedSpeakers->isNotEmpty())
    <section id="penceramah" class="scroll-mt-8" aria-labelledby="shared-event-speakers-title">
        <div class="mb-7">
            <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                <h2 id="shared-event-speakers-title" class="font-heading text-[1.75rem] leading-[1.15] tracking-[-0.01em] text-balance">{{ __('Penceramah majlis') }}</h2>
                <p class="font-mono text-xs text-[#6b645e]">{{ $sharedSpeakers->count() }}</p>
            </div>
        </div>

        <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($sharedSpeakers as $speaker)
                @php
                    $speakerPortraitUrl = $speaker->getFirstMedia('profile')?->getAvailableUrl(['profile_thumb']);
                    $speakerInitial = mb_strtoupper(mb_substr((string) ($speaker->name ?? '?'), 0, 1));
                    $speakerGender = \AIArmada\Persons\Enums\Gender::tryFrom((string) $speaker->getRawOriginal('gender'));
                    $speakerPlaceholderUrl = $speakerGender !== null ? $speaker->default_portrait_url : null;
                @endphp
                <li class="h-full">
                    <a href="{{ route('persons.show', $speaker) }}" wire:navigate class="mi-paper group flex h-full flex-col overflow-hidden rounded-[20px] border border-[#e6dccb] bg-[#fffdf9] transition duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:-translate-y-0.5 hover:border-[#c9962f]/45 hover:shadow-[0_18px_40px_-30px_rgba(20,52,46,0.55)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e]">
                        <div class="relative aspect-[3/4] w-full overflow-hidden bg-[#14342e]">
                            @if(filled($speakerPortraitUrl))
                                <img src="{{ $speakerPortraitUrl }}" alt="" aria-hidden="true" loading="lazy" class="size-full object-cover transition duration-700 ease-[cubic-bezier(0.22,1,0.36,1)] group-hover:scale-[1.03]">
                            @elseif($speakerPlaceholderUrl !== null)
                                <img src="{{ $speakerPlaceholderUrl }}" alt="" aria-hidden="true" loading="lazy" data-placeholder="speaker-image" data-placeholder-variant="{{ $speakerGender->value }}" class="size-full object-cover object-top transition duration-700 ease-[cubic-bezier(0.22,1,0.36,1)] group-hover:scale-[1.03]">
                            @else
                                <span class="mi-lattice-light pointer-events-none absolute inset-0 opacity-[0.08]" aria-hidden="true"></span>
                                <span class="relative flex size-full items-center justify-center font-heading text-6xl text-[#f2c867]" aria-hidden="true">{{ $speakerInitial }}</span>
                            @endif
                            <span class="pointer-events-none absolute inset-0 bg-[linear-gradient(to_top,rgba(11,43,37,0.35),rgba(11,43,37,0)_45%)]" aria-hidden="true"></span>
                        </div>
                        <div class="flex flex-1 flex-col p-4">
                            <p class="line-clamp-2 font-heading text-lg leading-snug text-[#14342e] transition-colors duration-300 group-hover:text-[#8a5f13]">{{ $speaker->name }}</p>
                            <p class="mt-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-[#8a5f13]">{{ __('Penceramah') }}</p>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif

@if($sharedKeyPeople->isNotEmpty())
    <section id="peranan" class="scroll-mt-8" aria-labelledby="shared-event-roles-title">
        <div class="mb-7">
            <h2 id="shared-event-roles-title" class="font-heading text-[1.75rem] leading-[1.15] tracking-[-0.01em] text-balance">{{ __('Peranan') }}</h2>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            @foreach($sharedKeyPeople as $role => $keyPeople)
                @php($roleLabel = \App\Shared\Events\Enums\EventKeyPersonRole::tryFrom((string) $role)?->getLabel() ?? \Illuminate\Support\Str::headline((string) $role))
                <div class="mi-paper rounded-[20px] border border-[#e6dccb] bg-[#fffdf9] p-5">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-[#8a5f13]">{{ $roleLabel }}</p>
                    <div class="mt-3.5 space-y-2.5">
                        @foreach($keyPeople as $keyPerson)
                            @if($keyPerson->person)
                                <a href="{{ route('persons.show', $keyPerson->person) }}" wire:navigate class="flex items-center gap-3 text-sm font-medium text-[#14342e] transition-colors duration-300 hover:text-[#8a5f13]">
                                    @php($keyPersonAvatarUrl = $keyPerson->person->getFirstMedia('avatar')?->getAvailableUrl(['thumb']))
                                    @if(filled($keyPersonAvatarUrl))
                                        <img src="{{ $keyPersonAvatarUrl }}" alt="" aria-hidden="true" loading="lazy" class="size-8 shrink-0 rounded-full border border-[#e6dccb] bg-[#f4f0e7] object-cover">
                                    @else
                                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-[#14342e] font-heading text-[13px] text-[#f2c867]" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $keyPerson->person->name, 0, 1)) }}</span>
                                    @endif
                                    <span class="min-w-0 truncate">{{ $keyPerson->person->name }}</span>
                                </a>
                            @elseif(filled($keyPerson->display_name))
                                <span class="flex items-center gap-3 text-sm font-medium text-[#14342e]">
                                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-[#e6dccb] text-[13px] font-semibold text-[#4c5b56]" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $keyPerson->display_name, 0, 1)) }}</span>
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
    @php($galleryCount = count($galleryImages))
    <section id="galeri" class="scroll-mt-8" aria-labelledby="shared-event-gallery-title">
        <div class="mb-7">
            <span class="block h-[2px] w-7 bg-[#c9962f]" aria-hidden="true"></span>
            <p class="mt-4 text-[11px] font-semibold uppercase tracking-[0.26em] text-[#8a5f13]">{{ __('Imej') }}</p>
            <div class="mt-2 flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                <h2 id="shared-event-gallery-title" class="font-heading text-[1.75rem] leading-[1.15] tracking-[-0.01em] text-balance">{{ __('Galeri') }}</h2>
                <p class="font-mono text-xs text-[#6b645e]">{{ $galleryCount }} {{ __('imej') }}</p>
            </div>
        </div>

        {{-- A lone image gets the full measure at 16:9; two or more fall into a
             steady 4:3 grid. Nothing here assumes a particular count. --}}
        <div class="grid gap-3 {{ $galleryCount === 1 ? 'grid-cols-1' : 'grid-cols-2' }}">
            @foreach($galleryImages as $galleryImage)
                <a
                    href="{{ $galleryImage['url'] }}"
                    target="_blank"
                    rel="noopener"
                    class="mi-plate group relative block overflow-hidden rounded-[16px] ring-1 ring-[#e6dccb] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#14342e]"
                >
                    <img
                        src="{{ $galleryImage['thumb'] }}"
                        alt="{{ $galleryImage['alt'] }}"
                        class="{{ $galleryCount === 1 ? 'aspect-[16/9]' : 'aspect-[4/3]' }} w-full object-cover transition duration-700 ease-[cubic-bezier(0.22,1,0.36,1)] group-hover:scale-[1.03]"
                        loading="lazy"
                    >
                    <span class="pointer-events-none absolute inset-0 bg-[linear-gradient(to_top,rgba(11,43,37,0.42),rgba(11,43,37,0)_45%)] opacity-0 transition-opacity duration-500 group-hover:opacity-100" aria-hidden="true"></span>
                    <span class="pointer-events-none absolute bottom-3 right-3 flex size-8 items-center justify-center rounded-full bg-[#f4f0e7]/95 text-[#14342e] opacity-0 transition duration-500 ease-[cubic-bezier(0.22,1,0.36,1)] group-hover:opacity-100" aria-hidden="true">
                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 14v6h6M20 10V4h-6M4 20l7-7M20 4l-7 7" /></svg>
                    </span>
                </a>
            @endforeach
        </div>
    </section>
@endif
