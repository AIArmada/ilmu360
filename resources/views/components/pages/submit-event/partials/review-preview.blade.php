@php
    use App\Enums\EventAgeGroup;
    use App\Enums\EventFormat;
    use App\Enums\EventGenderRestriction;
    use App\Enums\EventKeyPersonRole;
    use App\Enums\EventPrayerTime;
    use App\Enums\EventVisibility;
    use App\Models\Institution;
    use App\Models\Reference;
    use App\Models\Space;
    use App\Models\Person;
    use AIArmada\Events\Models\EventTerm;
    use App\Models\Venue;
    use AIArmada\Addressing\Support\AddressCountryResolver;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Collection;
    use Illuminate\Support\Str;

    $dash = '-';

    $asList = static function (mixed $value): array {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            $value = [$value];
        }

        return collect($value)
            ->map(function (mixed $item): mixed {
                if ($item instanceof \BackedEnum) {
                    return $item->value;
                }

                return $item;
            })
            ->filter(fn (mixed $item): bool => filled($item))
            ->values()
            ->all();
    };

    $toLabel = static function (mixed $value) use ($dash): string {
        if (! filled($value)) {
            return $dash;
        }

        return (string) $value;
    };

    $toJoined = static function (array $values) use ($dash): string {
        $values = collect($values)
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->values()
            ->all();

        return $values === [] ? $dash : implode(', ', $values);
    };

    $toScalar = static function (mixed $value): string {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if (! filled($value)) {
            return '';
        }

        return (string) $value;
    };

    $valueClass = static function (string $rendered) use ($dash): string {
        return $rendered === $dash ? 'text-slate-400' : 'font-medium text-slate-900';
    };

    $dtClass = 'text-xs font-medium text-slate-500';
    $ddBase = 'mt-1 text-sm leading-relaxed break-words';

    $submissionCountryId = is_string($get('submission_country_id')) ? $get('submission_country_id') : null;
    $submissionCountryName = filled($submissionCountryId)
        ? (string) (app(AddressCountryResolver::class)->resolve($submissionCountryId)?->name ?? '')
        : '';
    $previewTimezone = app(AddressCountryResolver::class)->timezoneFor($submissionCountryId)
        ?? config('app.timezone', 'UTC');

    $toTimeLabel = static function (mixed $value) use ($dash, $previewTimezone): string {
        if (! filled($value)) {
            return $dash;
        }

        try {
            $timeValue = (string) $value;

            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeValue) === 1) {
                return Carbon::parse($timeValue)->format('h:i A');
            }

            $parsed = Carbon::parse($timeValue);

            if (preg_match('/(Z|[+\-]\d{2}:?\d{2})$/', $timeValue) === 1) {
                return $parsed->setTimezone($previewTimezone)->format('h:i A');
            }

            return $parsed->format('h:i A');
        } catch (\Throwable) {
            return (string) $value;
        }
    };

    $eventTypeLabels = collect($asList($get('event_category_ids')))
        ->map(fn (string $id): string => app(\App\Contracts\EventCategoryCatalog::class)->options()[$id] ?? $id)
        ->all();

    $prayerTimeState = $get('prayer_time');
    if ($prayerTimeState instanceof EventPrayerTime) {
        $prayerTimeLabel = $prayerTimeState->getLabel();
    } else {
        $prayerTimeLabel = EventPrayerTime::tryFrom((string) $prayerTimeState)?->getLabel();
    }

    $showCustomTime = $prayerTimeState instanceof EventPrayerTime
        ? $prayerTimeState === EventPrayerTime::LainWaktu
        : (string) $prayerTimeState === EventPrayerTime::LainWaktu->value;

    $eventDate = filled($get('event_date'))
        ? Carbon::parse((string) $get('event_date'))->translatedFormat('d M Y')
        : null;

    $formatEnum = EventFormat::tryFrom($toScalar($get('event_format')));
    $visibilityEnum = EventVisibility::tryFrom($toScalar($get('visibility')));
    $genderEnum = EventGenderRestriction::tryFrom($toScalar($get('gender')));

    $ageGroupLabels = collect($asList($get('age_group')))
        ->map(function (mixed $value): string {
            $enum = EventAgeGroup::tryFrom((string) $value);

            return $enum?->getLabel() ?? (string) $value;
        })
        ->all();

    $languageIds = $asList($get('languages'));
    $preferredLanguageLabels = \App\Support\Language\MalaysiaLanguageCatalog::labels();
    $languageMap = \App\Models\Language::query()
        ->whereIn('id', $languageIds)
        ->get(['id', 'code', 'name'])
        ->mapWithKeys(fn ($language): array => [
            $language->id => $preferredLanguageLabels[$language->code]
                ?? $language->name
                ?? Str::upper($language->code),
        ])
        ->toArray();
    $languageLabels = collect($languageIds)
        ->map(fn (mixed $id): ?string => $languageMap[$id] ?? null)
        ->filter()
        ->all();

    $tagFields = [
        'domain_tags' => $asList($get('domain_tags')),
        'discipline_tags' => $asList($get('discipline_tags')),
        'source_tags' => $asList($get('source_tags')),
        'issue_tags' => $asList($get('issue_tags')),
    ];

    $tagValues = collect($tagFields)
        ->flatten()
        ->map(fn (mixed $value): string => (string) $value)
        ->filter(fn (string $value): bool => filled($value))
        ->unique()
        ->values();

    $tagIds = $tagValues
        ->filter(fn (string $value): bool => Str::isUuid($value))
        ->all();

    $tagLabelMap = $tagValues
        ->reject(fn (string $value): bool => Str::isUuid($value))
        ->mapWithKeys(fn (string $value): array => [$value => $value])
        ->toArray();

    if ($tagIds !== []) {
        $tagLabelMap = array_merge(
            $tagLabelMap,
            EventTerm::query()
                ->whereIn('id', $tagIds)
                ->get()
                ->mapWithKeys(fn (EventTerm $term): array => [(string) $term->id => (string) $term->name])
                ->toArray(),
        );
    }

    $resolveTagLabel = static function (mixed $value) use ($tagLabelMap): ?string {
        $key = (string) $value;

        if (! filled($key)) {
            return null;
        }

        return $tagLabelMap[$key] ?? (! Str::isUuid($key) ? $key : null);
    };

    $domainLabels = collect($tagFields['domain_tags'])->map($resolveTagLabel)->filter()->all();
    $disciplineLabels = collect($tagFields['discipline_tags'])->map($resolveTagLabel)->filter()->all();
    $sourceLabels = collect($tagFields['source_tags'])->map($resolveTagLabel)->filter()->all();
    $issueLabels = collect($tagFields['issue_tags'])->map($resolveTagLabel)->filter()->all();

    $referenceIds = $asList($get('references'));
    $referenceMap = Reference::query()->active()->whereIn('id', $referenceIds)->pluck('title', 'id')->toArray();
    $referenceLabels = collect($referenceIds)
        ->map(fn (mixed $id): ?string => $referenceMap[$id] ?? null)
        ->filter()
        ->all();

    $primaryOrganizerId = $get('primary_organizer_id');
    $primaryOrganizerKind = $get('primary_organizer_kind');

    if (! in_array($primaryOrganizerKind, ['institution', 'person'], true) && filled($primaryOrganizerId)) {
        if (Institution::query()->whereKey($primaryOrganizerId)->exists()) {
            $primaryOrganizerKind = 'institution';
        } elseif (Person::query()->whereKey($primaryOrganizerId)->exists()) {
            $primaryOrganizerKind = 'person';
        }
    }

    $organizerKindLabel = $primaryOrganizerKind === 'person'
        ? __('Penceramah')
        : ($primaryOrganizerKind === 'institution' ? __('Institusi') : $dash);

    $selectedPersonIds = $asList($get('persons'));
    $personIds = collect($selectedPersonIds)
        ->push($primaryOrganizerKind === 'person' ? ($get('primary_organizer_person_id') ?: $primaryOrganizerId) : null)
        ->merge(collect((array) $get('other_key_people'))->map(
            fn (mixed $keyPerson): mixed => is_array($keyPerson) ? ($keyPerson['involveable_id'] ?? null) : null,
        ))
        ->filter(fn (mixed $id): bool => filled($id) && Str::isUuid((string) $id))
        ->map(fn (mixed $id): string => (string) $id)
        ->unique()
        ->values()
        ->all();
    $personMap = Person::query()
        ->whereIn('id', $personIds)
        ->get()
        ->mapWithKeys(fn (Person $person): array => [(string) $person->id => $person->formatted_name])
        ->toArray();
    $personLabels = collect($selectedPersonIds)
        ->map(fn (mixed $id): ?string => $personMap[$id] ?? null)
        ->filter()
        ->all();

    $otherKeyPeopleLabels = collect((array) $get('other_key_people'))
        ->map(function (mixed $keyPerson) use ($personMap): ?string {
            if (! is_array($keyPerson)) {
                return null;
            }

            $role = EventKeyPersonRole::tryFrom((string) ($keyPerson['role_code'] ?? ''));
            $personId = (string) ($keyPerson['involveable_id'] ?? '');
            $name = is_string($keyPerson['display_name'] ?? null) ? trim((string) $keyPerson['display_name']) : '';
            $displayName = $personMap[$personId] ?? $name;

            if (! $role instanceof EventKeyPersonRole || $displayName === '') {
                return null;
            }

            return $role->getLabel().': '.$displayName;
        })
        ->filter()
        ->values()
        ->all();

    $institutionIds = collect([
        $primaryOrganizerKind === 'institution' ? $primaryOrganizerId : $get('primary_organizer_institution_id'),
        $get('location_institution_id'),
    ])
        ->filter(fn (mixed $id): bool => filled($id) && Str::isUuid((string) $id))
        ->map(fn (mixed $id): string => (string) $id)
        ->unique()
        ->values()
        ->all();
    $institutionMap = Institution::query()
        ->whereIn('id', $institutionIds)
        ->with('names')
        ->get()
        ->mapWithKeys(fn (Institution $institution): array => [(string) $institution->id => $institution->display_name])
        ->toArray();

    $venueId = $get('location_venue_id');
    $venueName = filled($venueId) ? Venue::query()->whereKey($venueId)->value('name') : null;

    $spaceIds = collect($asList($get('space_ids')))
        ->merge($asList($get('space_id')))
        ->filter(fn (mixed $id): bool => filled($id) && Str::isUuid((string) $id))
        ->map(fn (mixed $id): string => (string) $id)
        ->unique()
        ->values()
        ->all();
    $spaceMap = $spaceIds === []
        ? []
        : Space::query()->whereIn('id', $spaceIds)->pluck('name', 'id')->toArray();
    $spaceLabels = collect($spaceIds)
        ->map(fn (string $id): ?string => isset($spaceMap[$id]) ? (string) $spaceMap[$id] : null)
        ->filter()
        ->all();

    $organizerName = $primaryOrganizerKind === 'institution'
        ? ($institutionMap[(string) $primaryOrganizerId] ?? null)
        : ($personMap[(string) ($get('primary_organizer_person_id') ?: $primaryOrganizerId)] ?? null);

    $locationLabel = null;
    if ($toScalar($get('event_format')) === EventFormat::Online->value) {
        $locationLabel = __('Online');
    } elseif ($primaryOrganizerKind === 'institution' && (bool) $get('location_same_as_institution')) {
        $locationLabel = $institutionMap[(string) $primaryOrganizerId] ?? null;
    } elseif ((string) $get('location_type') === 'institution') {
        $locationLabel = $institutionMap[(string) $get('location_institution_id')] ?? null;
    } elseif ((string) $get('location_type') === 'venue') {
        $locationLabel = $venueName;
    }

    $eventUrl = $toScalar($get('event_url'));
    $liveUrl = $toScalar($get('live_url'));

    $galleryCount = count($asList($get('gallery')));
    $hasCover = filled($get('cover'));
    $hasPoster = filled($get('poster'));
@endphp

<div class="divide-y divide-slate-200/70">
    <section class="pb-6">
        <h4 class="text-sm font-semibold text-emerald-950">{{ __('Majlis & Topik') }}</h4>
        <dl class="mt-5 grid gap-x-6 gap-y-5 text-sm md:grid-cols-2">
            <div>
                <dt class="{{ $dtClass }}">{{ __('Tajuk Majlis') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($toLabel($get('title'))) }}">{{ $toLabel($get('title')) }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('Jenis Majlis') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($toJoined($eventTypeLabels)) }}">{{ $toJoined($eventTypeLabels) }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('Topik / Bidang') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($toJoined($domainLabels)) }}">{{ $toJoined($domainLabels) }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('Topik Lebih Khusus') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($toJoined($disciplineLabels)) }}">{{ $toJoined($disciplineLabels) }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('Sumber Utama') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($toJoined($sourceLabels)) }}">{{ $toJoined($sourceLabels) }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('Tema / Isu') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($toJoined($issueLabels)) }}">{{ $toJoined($issueLabels) }}</dd>
            </div>
            <div class="md:col-span-2">
                <dt class="{{ $dtClass }}">{{ __('Rujukan Kitab') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($toJoined($referenceLabels)) }}">{{ $toJoined($referenceLabels) }}</dd>
            </div>
            <div class="md:col-span-2">
                <dt class="{{ $dtClass }}">{{ __('Keterangan') }}</dt>
                <dd class="mt-1 max-w-3xl space-y-2 break-words text-sm leading-7 {{ filled($get('description')) ? 'font-medium text-slate-900' : 'text-slate-400' }}">{!! filled($get('description')) ? $get('description') : $dash !!}</dd>
            </div>
        </dl>
    </section>

    <section class="py-6">
        <h4 class="text-sm font-semibold text-emerald-950">{{ __('Tarikh, Masa & Kehadiran') }}</h4>
        <dl class="mt-5 grid gap-x-6 gap-y-5 text-sm md:grid-cols-2">
            <div>
                <dt class="{{ $dtClass }}">{{ __('Tarikh') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($toLabel($eventDate)) }}">{{ $toLabel($eventDate) }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('Waktu') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($toLabel($prayerTimeLabel)) }}">{{ $toLabel($prayerTimeLabel) }}</dd>
            </div>
            @if ($showCustomTime)
                <div>
                    <dt class="{{ $dtClass }}">{{ __('Masa Mula') }}</dt>
                    <dd class="{{ $ddBase }} {{ $valueClass($toTimeLabel($get('custom_time'))) }}">{{ $toTimeLabel($get('custom_time')) }}</dd>
                </div>
            @endif
            <div>
                <dt class="{{ $dtClass }}">{{ __('Masa Akhir') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($toTimeLabel($get('end_time'))) }}">{{ $toTimeLabel($get('end_time')) }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('Jantina') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($toLabel($genderEnum?->getLabel())) }}">{{ $toLabel($genderEnum?->getLabel()) }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('Peringkat Umur') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($toJoined($ageGroupLabels)) }}">{{ $toJoined($ageGroupLabels) }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('Kanak-kanak Dibenarkan') }}</dt>
                <dd class="{{ $ddBase }} font-medium text-slate-900">{{ (bool) $get('children_allowed') ? __('Ya') : __('Tidak') }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('Terbuka untuk Muslim Sahaja') }}</dt>
                <dd class="{{ $ddBase }} font-medium text-slate-900">{{ (bool) $get('is_muslim_only') ? __('Ya') : __('Tidak') }}</dd>
            </div>
            <div class="md:col-span-2">
                <dt class="{{ $dtClass }}">{{ __('Bahasa') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($toJoined($languageLabels)) }}">{{ $toJoined($languageLabels) }}</dd>
            </div>
        </dl>
    </section>

    <section class="py-6">
        <h4 class="text-sm font-semibold text-emerald-950">{{ __('Format, Penganjur & Lokasi') }}</h4>
        <dl class="mt-5 grid gap-x-6 gap-y-5 text-sm md:grid-cols-2">
            <div>
                <dt class="{{ $dtClass }}">{{ __('Negara') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($toLabel($submissionCountryName)) }}">{{ $toLabel($submissionCountryName) }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('Format Majlis') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($toLabel($formatEnum?->label())) }}">{{ $toLabel($formatEnum?->label()) }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('Keterlihatan') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($toLabel($visibilityEnum?->getLabel())) }}">{{ $toLabel($visibilityEnum?->getLabel()) }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('Pautan Majlis') }}</dt>
                <dd class="{{ $ddBase }} {{ $eventUrl !== '' ? 'font-medium' : 'text-slate-400' }}">
                    @if ($eventUrl !== '')
                        <a href="{{ $eventUrl }}" target="_blank" rel="noopener" class="break-all text-emerald-700 underline decoration-emerald-200 underline-offset-2 hover:text-emerald-900">{{ $eventUrl }}</a>
                    @else
                        {{ $dash }}
                    @endif
                </dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('Pautan Siaran Langsung') }}</dt>
                <dd class="{{ $ddBase }} {{ $liveUrl !== '' ? 'font-medium' : 'text-slate-400' }}">
                    @if ($liveUrl !== '')
                        <a href="{{ $liveUrl }}" target="_blank" rel="noopener" class="break-all text-emerald-700 underline decoration-emerald-200 underline-offset-2 hover:text-emerald-900">{{ $liveUrl }}</a>
                    @else
                        {{ $dash }}
                    @endif
                </dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('Jenis Penganjur') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($organizerKindLabel) }}">{{ $organizerKindLabel }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('Penganjur') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($toLabel($organizerName)) }}">{{ $toLabel($organizerName) }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('Lokasi') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($toLabel($locationLabel)) }}">{{ $toLabel($locationLabel) }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('Ruang') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($toJoined($spaceLabels)) }}">{{ $toJoined($spaceLabels) }}</dd>
            </div>
        </dl>
    </section>

    <section class="pt-6">
        <h4 class="text-sm font-semibold text-emerald-950">{{ __('Penceramah & Media') }}</h4>
        <dl class="mt-5 grid gap-x-6 gap-y-5 text-sm md:grid-cols-2">
            <div class="md:col-span-2">
                <dt class="{{ $dtClass }}">{{ __('Pilih Penceramah') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($toJoined($personLabels)) }}">{{ $toJoined($personLabels) }}</dd>
            </div>
            <div class="md:col-span-2">
                <dt class="{{ $dtClass }}">{{ __('Peranan Lain') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($toJoined($otherKeyPeopleLabels)) }}">{{ $toJoined($otherKeyPeopleLabels) }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('Gambar Cover Majlis') }}</dt>
                <dd class="{{ $ddBase }} font-medium text-slate-900">{{ $hasCover ? __('Ya') : __('Tidak') }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('Poster Hebahan') }}</dt>
                <dd class="{{ $ddBase }} font-medium text-slate-900">{{ $hasPoster ? __('Ya') : __('Tidak') }}</dd>
            </div>
            <div>
                <dt class="{{ $dtClass }}">{{ __('Galeri') }}</dt>
                <dd class="{{ $ddBase }} {{ $valueClass($galleryCount > 0 ? (string) $galleryCount : $dash) }}">{{ $galleryCount > 0 ? (string) $galleryCount : $dash }}</dd>
            </div>
        </dl>
    </section>
</div>
