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

    $submissionCountryId = is_string($get('submission_country_id')) ? $get('submission_country_id') : null;
    $previewTimezone = app(AddressCountryResolver::class)->timezoneFor($submissionCountryId)
        ?? config('app.timezone', 'UTC');

    $toTimeLabel = static function (mixed $value) use ($dash, $previewTimezone): string {
        if (! filled($value)) {
            return $dash;
        }

        try {
            $timeValue = (string) $value;

            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeValue) === 1) {
                return $timeValue;
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

    $showCustomTime = ! $hasReligiousContext || ($prayerTimeState instanceof EventPrayerTime
        ? $prayerTimeState === EventPrayerTime::LainWaktu
        : (string) $prayerTimeState === EventPrayerTime::LainWaktu->value);

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
    $preferredLanguageLabels = [
        'ms' => 'Bahasa Melayu',
        'ar' => 'Bahasa Arab',
        'en' => 'Bahasa Inggeris',
        'id' => 'Bahasa Indonesia',
        'zh' => 'Bahasa Cina',
        'ta' => 'Bahasa Tamil',
        'jv' => 'Bahasa Jawa',
    ];
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
    $referenceMap = Reference::query()->whereIn('id', $referenceIds)->pluck('title', 'id')->toArray();
    $referenceLabels = collect($referenceIds)
        ->map(fn (mixed $id): ?string => $referenceMap[$id] ?? null)
        ->filter()
        ->all();

    $personIds = $asList($get('persons'));
    $personMap = Person::query()->whereIn('id', $personIds)->pluck('name', 'id')->toArray();
    $personLabels = collect($personIds)
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

    $primaryOrganizerId = $get('primary_organizer_id');
    $primaryOrganizerKind = $get('primary_organizer_kind');

    if (! in_array($primaryOrganizerKind, ['institution', 'person'], true) && filled($primaryOrganizerId)) {
        if (Institution::query()->whereKey($primaryOrganizerId)->exists()) {
            $primaryOrganizerKind = 'institution';
        } elseif (Person::query()->whereKey($primaryOrganizerId)->exists()) {
            $primaryOrganizerKind = 'person';
        }
    }

    $institutionIds = collect([
        $primaryOrganizerKind === 'institution' ? $primaryOrganizerId : $get('primary_organizer_institution_id'),
        $get('location_institution_id'),
    ])
        ->filter()
        ->values()
        ->all();
    $institutionMap = Institution::query()
        ->whereIn('id', $institutionIds)
        ->pluck('name', 'id')
        ->toArray();

    $venueId = $get('location_venue_id');
    $venueName = filled($venueId) ? Venue::query()->whereKey($venueId)->value('name') : null;

    $spaceId = $get('space_id');
    $spaceName = filled($spaceId) ? Space::query()->whereKey($spaceId)->value('name') : null;

    $organizerName = $primaryOrganizerKind === 'institution'
        ? ($institutionMap[(string) $primaryOrganizerId] ?? null)
        : (Person::query()->whereKey($get('primary_organizer_person_id') ?: $primaryOrganizerId)->value('name'));

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

    $galleryCount = count($asList($get('gallery')));
    $hasCover = filled($get('cover'));
    $hasPoster = filled($get('poster'));
@endphp

<div class="space-y-4">
    <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
        <h4 class="text-sm font-semibold text-slate-900">{{ __('Maklumat Majlis') }}</h4>
        <dl class="mt-3 grid gap-3 text-sm md:grid-cols-2">
            <div>
                <dt class="text-slate-500">{{ __('Tajuk Majlis') }}</dt>
                <dd class="font-medium text-slate-900">{{ $toLabel($get('title')) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">{{ __('Jenis Majlis') }}</dt>
                <dd class="font-medium text-slate-900">{{ $toJoined($eventTypeLabels) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">{{ __('Tarikh') }}</dt>
                <dd class="font-medium text-slate-900">{{ $toLabel($eventDate) }}</dd>
            </div>
            @if ($hasReligiousContext)
                <div>
                    <dt class="text-slate-500">{{ __('Waktu') }}</dt>
                    <dd class="font-medium text-slate-900">{{ $toLabel($prayerTimeLabel) }}</dd>
                </div>
            @endif
            @if ($showCustomTime)
                <div>
                    <dt class="text-slate-500">{{ __('Masa Mula') }}</dt>
                    <dd class="font-medium text-slate-900">{{ $toTimeLabel($get('custom_time')) }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-slate-500">{{ __('Masa Akhir') }}</dt>
                <dd class="font-medium text-slate-900">{{ $toTimeLabel($get('end_time')) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">{{ __('Format Majlis') }}</dt>
                <dd class="font-medium text-slate-900">{{ $toLabel($formatEnum?->label()) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">{{ __('Keterlihatan') }}</dt>
                <dd class="font-medium text-slate-900">{{ $toLabel($visibilityEnum?->getLabel()) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">{{ __('Pautan Majlis') }}</dt>
                <dd class="font-medium text-slate-900">{{ $toLabel($get('event_url')) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">{{ __('Pautan Siaran Langsung') }}</dt>
                <dd class="font-medium text-slate-900">{{ $toLabel($get('live_url')) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">{{ __('Jantina') }}</dt>
                <dd class="font-medium text-slate-900">{{ $toLabel($genderEnum?->getLabel()) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">{{ __('Peringkat Umur') }}</dt>
                <dd class="font-medium text-slate-900">{{ $toJoined($ageGroupLabels) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">{{ __('Kanak-kanak Dibenarkan') }}</dt>
                <dd class="font-medium text-slate-900">{{ (bool) $get('children_allowed') ? __('Ya') : __('Tidak') }}</dd>
            </div>
            @if ($hasReligiousContext)
                <div>
                    <dt class="text-slate-500">{{ __('Terbuka untuk Muslim Sahaja') }}</dt>
                    <dd class="font-medium text-slate-900">{{ (bool) $get('is_muslim_only') ? __('Ya') : __('Tidak') }}</dd>
                </div>
            @endif
            <div class="md:col-span-2">
                <dt class="text-slate-500">{{ __('Bahasa') }}</dt>
                <dd class="font-medium text-slate-900">{{ $toJoined($languageLabels) }}</dd>
            </div>
            <div class="md:col-span-2">
                <dt class="text-slate-500">{{ __('Keterangan') }}</dt>
                <dd class="font-medium text-slate-900">{!! filled($get('description')) ? $get('description') : $dash !!}</dd>
            </div>
        </dl>
    </div>

    <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
        <h4 class="text-sm font-semibold text-slate-900">{{ __('Topik & Rujukan') }}</h4>
        <dl class="mt-3 grid gap-3 text-sm md:grid-cols-2">
            <div>
                <dt class="text-slate-500">{{ __('Kategori') }}</dt>
                <dd class="font-medium text-slate-900">{{ $toJoined($domainLabels) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">{{ __('Bidang Ilmu') }}</dt>
                <dd class="font-medium text-slate-900">{{ $toJoined($disciplineLabels) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">{{ __('Sumber Utama') }}</dt>
                <dd class="font-medium text-slate-900">{{ $toJoined($sourceLabels) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">{{ __('Tema / Isu') }}</dt>
                <dd class="font-medium text-slate-900">{{ $toJoined($issueLabels) }}</dd>
            </div>
            <div class="md:col-span-2">
                <dt class="text-slate-500">{{ __('Rujukan Kitab') }}</dt>
                <dd class="font-medium text-slate-900">{{ $toJoined($referenceLabels) }}</dd>
            </div>
        </dl>
    </div>

    <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
        <h4 class="text-sm font-semibold text-slate-900">{{ __('Penganjur & Lokasi') }}</h4>
        <dl class="mt-3 grid gap-3 text-sm md:grid-cols-2">
            <div>
                <dt class="text-slate-500">{{ __('Jenis Penganjur') }}</dt>
                <dd class="font-medium text-slate-900">
                    {{ $primaryOrganizerKind === 'speaker' ? __('Penceramah') : ($primaryOrganizerKind === 'institution' ? __('Institusi') : $dash) }}
                </dd>
            </div>
            <div>
                <dt class="text-slate-500">{{ __('Penganjur') }}</dt>
                <dd class="font-medium text-slate-900">{{ $toLabel($organizerName) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">{{ __('Lokasi') }}</dt>
                <dd class="font-medium text-slate-900">{{ $toLabel($locationLabel) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">{{ __('Ruang') }}</dt>
                <dd class="font-medium text-slate-900">{{ $toLabel($spaceName) }}</dd>
            </div>
            <div class="md:col-span-2">
                <dt class="text-slate-500">{{ __('Pilih Penceramah') }}</dt>
                <dd class="font-medium text-slate-900">{{ $toJoined($personLabels) }}</dd>
            </div>
            <div class="md:col-span-2">
                <dt class="text-slate-500">{{ __('Peranan Lain') }}</dt>
                <dd class="font-medium text-slate-900">{{ $toJoined($otherKeyPeopleLabels) }}</dd>
            </div>
        </dl>
    </div>

    <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
        <h4 class="text-sm font-semibold text-slate-900">{{ __('Penceramah & Media') }}</h4>
        <dl class="mt-3 grid gap-3 text-sm md:grid-cols-2">
            <div>
                <dt class="text-slate-500">{{ __('Gambar Cover Majlis') }}</dt>
                <dd class="font-medium text-slate-900">{{ $hasCover ? __('Ya') : __('Tidak') }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">{{ __('Poster Hebahan') }}</dt>
                <dd class="font-medium text-slate-900">{{ $hasPoster ? __('Ya') : __('Tidak') }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">{{ __('Galeri') }}</dt>
                <dd class="font-medium text-slate-900">{{ $galleryCount > 0 ? (string) $galleryCount : $dash }}</dd>
            </div>
        </dl>
    </div>
</div>
