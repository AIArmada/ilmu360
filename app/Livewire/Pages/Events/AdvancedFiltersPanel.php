<?php

namespace App\Livewire\Pages\Events;

use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\TagType;
use App\Enums\TimingMode;
use App\Forms\SharedFormSchema;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\State;
use App\Models\Tag;
use App\Models\Venue;
use App\Support\Cache\SafeModelCache;
use App\Support\Location\FederalTerritoryLocation;
use App\Support\Location\PreferredCountryResolver;
use App\Support\Location\PublicGeolocationPermission;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Nnjeim\World\Models\Language;

class AdvancedFiltersPanel extends Component implements HasForms
{
    use InteractsWithForms;

    /**
     * @var array<string, mixed>
     */
    public array $filterData = [];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function mount(array $filters = []): void
    {
        $normalized = $filters === []
            ? $this->defaultFilterData()
            : $this->normalizedFilterData($filters);

        $this->filterData = $normalized;
        $this->getForm('form')->fill($normalized);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('filterData')
            ->schema([
                Section::make(__('Advanced Filters'))
                    ->extraAttributes(['class' => 'mi-advanced-filter-section'])
                    ->description(__('Refine events using format, timing, audience, speakers, and links.'))
                    ->schema([
                        Section::make(__('Time & Date'))
                            ->extraAttributes(['class' => 'mi-advanced-filter-group'])
                            ->description(__('Set the date range when events are held, then choose timing mode.'))
                            ->columns(['default' => 1, 'md' => 2, 'xl' => 5])
                            ->schema([
                                DatePicker::make('starts_after')
                                    ->label(__('Held From Date'))
                                    ->helperText(__('Filters events held on or after this date.'))
                                    ->native()
                                    ->maxDate(fn (Get $get): ?string => $get('starts_before'))
                                    ->live(),

                                DatePicker::make('starts_before')
                                    ->label(__('Held Until Date'))
                                    ->helperText(__('Filters events held on or before this date.'))
                                    ->native()
                                    ->minDate(fn (Get $get): ?string => $get('starts_after'))
                                    ->live(),

                                Select::make('time_scope')
                                    ->label(__('Time Scope'))
                                    ->options([
                                        'upcoming' => __('Upcoming'),
                                        'past' => __('Past'),
                                        'all' => __('All Time'),
                                    ])
                                    ->default('upcoming')
                                    ->live(),

                                Select::make('timing_mode')
                                    ->label(__('Timing Mode'))
                                    ->placeholder(__('Any'))
                                    ->options([
                                        TimingMode::Absolute->value => TimingMode::Absolute->label(),
                                        TimingMode::PrayerRelative->value => TimingMode::PrayerRelative->label(),
                                    ])
                                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                                        if ($state !== TimingMode::PrayerRelative->value) {
                                            $set('prayer_time', null);
                                        }

                                        if ($state !== TimingMode::Absolute->value) {
                                            $set('starts_time_from', null);
                                            $set('starts_time_until', null);
                                        }
                                    })
                                    ->live(),

                                Select::make('prayer_time')
                                    ->label(__('Prayer Time'))
                                    ->placeholder(__('Any'))
                                    ->visible(fn (Get $get): bool => $get('timing_mode') === TimingMode::PrayerRelative->value)
                                    ->searchable()
                                    ->options(collect(EventPrayerTime::cases())
                                        ->mapWithKeys(fn (EventPrayerTime $prayerTime): array => [$prayerTime->value => $prayerTime->getLabel()])
                                        ->all())
                                    ->live(),

                                TimePicker::make('starts_time_from')
                                    ->label(__('Masa Dari'))
                                    ->helperText(__('Tapis berdasarkan masa mula majlis dari waktu ini.'))
                                    ->placeholder(__('Any'))
                                    ->seconds(false)
                                    ->native(false)
                                    ->visible(fn (Get $get): bool => $get('timing_mode') === TimingMode::Absolute->value)
                                    ->live(),

                                TimePicker::make('starts_time_until')
                                    ->label(__('Masa Hingga'))
                                    ->helperText(__('Tapis berdasarkan masa mula majlis hingga waktu ini.'))
                                    ->placeholder(__('Any'))
                                    ->seconds(false)
                                    ->native(false)
                                    ->visible(fn (Get $get): bool => $get('timing_mode') === TimingMode::Absolute->value)
                                    ->live(),
                            ]),

                        Section::make(__('Location'))
                            ->extraAttributes(['class' => 'mi-advanced-filter-group'])
                            ->description(__('Narrow events by geography, institution, and venue.'))
                            ->columns(['default' => 1, 'md' => 2, 'lg' => 3])
                            ->schema([
                                Hidden::make('country_id'),

                                Select::make('state_id')
                                    ->label(__('State'))
                                    ->placeholder(__('All States'))
                                    ->options(fn (): array => $this->states()
                                        ->pluck('name', 'id')
                                        ->mapWithKeys(fn (string $name, mixed $id): array => [(string) $id => $name])
                                        ->all())
                                    ->searchable()
                                    ->disabled(fn (Get $get): bool => ! filled($get('country_id')))
                                    ->live()
                                    ->afterStateUpdated(function (Set $set): void {
                                        $set('district_id', null);
                                        $set('subdistrict_id', null);
                                        $set('institution_id', null);
                                        $set('venue_id', null);
                                    }),

                                Select::make('district_id')
                                    ->label(__('District'))
                                    ->placeholder(__('All Districts'))
                                    ->options(fn (Get $get): array => collect(SharedFormSchema::districtOptionsForState($get('state_id')))
                                        ->mapWithKeys(fn (string $name, mixed $id): array => [(string) $id => $name])
                                        ->all())
                                    ->disabled(fn (Get $get): bool => ! filled($get('state_id')))
                                    ->visible(fn (Get $get): bool => filled($get('state_id')) && ! FederalTerritoryLocation::isFederalTerritoryStateId($get('state_id')))
                                    ->searchable()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set): void {
                                        $set('subdistrict_id', null);
                                        $set('institution_id', null);
                                        $set('venue_id', null);
                                    }),

                                Select::make('subdistrict_id')
                                    ->label(__('Bandar / Mukim / Zon'))
                                    ->placeholder(__('All Subdistricts'))
                                    ->options(fn (Get $get): array => collect(SharedFormSchema::subdistrictOptionsForSelection($get('state_id'), $get('district_id')))
                                        ->mapWithKeys(fn (string $name, mixed $id): array => [(string) $id => $name])
                                        ->all())
                                    ->disabled(fn (Get $get): bool => ! SharedFormSchema::shouldShowSubdistrictField($get('state_id'), $get('district_id')))
                                    ->searchable()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set): void {
                                        $set('institution_id', null);
                                        $set('venue_id', null);
                                    }),

                                Select::make('institution_id')
                                    ->label(__('Institution'))
                                    ->placeholder(__('Any Institution'))
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (Get $get, string $search): array => $this->searchInstitutionOptions(
                                        countryId: $this->normalizeNullableString($get('country_id')),
                                        stateId: $this->normalizeNullableString($get('state_id')),
                                        districtId: $this->normalizeNullableString($get('district_id')),
                                        subdistrictId: $this->normalizeNullableString($get('subdistrict_id')),
                                        search: $search,
                                    ))
                                    ->getOptionLabelUsing(fn (string $value): ?string => $this->institutionOptionLabel($value))
                                    ->helperText(__('Pilihan mengikut lokasi yang dipilih.'))
                                    ->live(),

                                Select::make('venue_id')
                                    ->label(__('Tempat'))
                                    ->placeholder(__('Any Venue'))
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (Get $get, string $search): array => $this->searchVenueOptions(
                                        countryId: $this->normalizeNullableString($get('country_id')),
                                        stateId: $this->normalizeNullableString($get('state_id')),
                                        districtId: $this->normalizeNullableString($get('district_id')),
                                        subdistrictId: $this->normalizeNullableString($get('subdistrict_id')),
                                        search: $search,
                                    ))
                                    ->getOptionLabelUsing(fn (string $value): ?string => $this->venueOptionLabel($value))
                                    ->helperText(__('Pilihan mengikut lokasi yang dipilih.'))
                                    ->live(),

                                TextInput::make('radius_km')
                                    ->label(__('Radius (km)'))
                                    ->helperText(__('Applied when searching nearby events from your detected location.'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(1000)
                                    ->step(1)
                                    ->extraInputAttributes(['min' => 1, 'max' => 1000, 'step' => 1])
                                    ->extraFieldWrapperAttributes([
                                        'data-testid' => 'advanced-nearby-radius',
                                        'x-cloak' => true,
                                        'x-bind:hidden' => '! geolocationPermitted',
                                        ...(app(PublicGeolocationPermission::class)->isGranted() ? [] : ['hidden' => 'hidden']),
                                    ])
                                    ->suffix(__('km'))
                                    ->visible(fn (Get $get): bool => filled($get('lat')) && filled($get('lng')))
                                    ->live(),
                            ]),

                        Section::make(__('People & Content'))
                            ->extraAttributes(['class' => 'mi-advanced-filter-group'])
                            ->description(__('Filter by speakers, categories, knowledge fields, themes, and references.'))
                            ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                            ->schema([
                                Select::make('speaker_ids')
                                    ->label(__('Speaker'))
                                    ->placeholder(__('Any Speaker'))
                                    ->searchable()
                                    ->multiple()
                                    ->getSearchResultsUsing(fn (string $search): array => $this->searchSpeakerOptions($search))
                                    ->getOptionLabelsUsing(fn (array $values): array => $this->speakerOptionLabels($values))
                                    ->live(),

                                Select::make('key_person_roles')
                                    ->label(__('Peranan Lain'))
                                    ->placeholder(__('Any Role'))
                                    ->searchable()
                                    ->multiple()
                                    ->options(EventKeyPersonRole::nonSpeakerOptions())
                                    ->live(),

                                Select::make('person_in_charge_ids')
                                    ->label(__('PIC / Penyelaras'))
                                    ->placeholder(__('Any PIC / Penyelaras'))
                                    ->searchable()
                                    ->multiple()
                                    ->getSearchResultsUsing(fn (string $search): array => $this->searchSpeakerOptions($search))
                                    ->getOptionLabelsUsing(fn (array $values): array => $this->speakerOptionLabels($values))
                                    ->live(),

                                TextInput::make('person_in_charge_search')
                                    ->label(__('Nama PIC / Penyelaras'))
                                    ->placeholder(__('Cari nama PIC / Penyelaras'))
                                    ->maxLength(255)
                                    ->live(onBlur: true),

                                Select::make('moderator_ids')
                                    ->label(__('Moderator'))
                                    ->placeholder(__('Any Moderator'))
                                    ->searchable()
                                    ->multiple()
                                    ->getSearchResultsUsing(fn (string $search): array => $this->searchSpeakerOptions($search))
                                    ->getOptionLabelsUsing(fn (array $values): array => $this->speakerOptionLabels($values))
                                    ->live(),

                                Select::make('imam_ids')
                                    ->label(__('Imam'))
                                    ->placeholder(__('Any Imam'))
                                    ->searchable()
                                    ->multiple()
                                    ->getSearchResultsUsing(fn (string $search): array => $this->searchSpeakerOptions($search))
                                    ->getOptionLabelsUsing(fn (array $values): array => $this->speakerOptionLabels($values))
                                    ->live(),

                                Select::make('khatib_ids')
                                    ->label(__('Khatib'))
                                    ->placeholder(__('Any Khatib'))
                                    ->searchable()
                                    ->multiple()
                                    ->getSearchResultsUsing(fn (string $search): array => $this->searchSpeakerOptions($search))
                                    ->getOptionLabelsUsing(fn (array $values): array => $this->speakerOptionLabels($values))
                                    ->live(),

                                Select::make('bilal_ids')
                                    ->label(__('Bilal'))
                                    ->placeholder(__('Any Bilal'))
                                    ->searchable()
                                    ->multiple()
                                    ->getSearchResultsUsing(fn (string $search): array => $this->searchSpeakerOptions($search))
                                    ->getOptionLabelsUsing(fn (array $values): array => $this->speakerOptionLabels($values))
                                    ->live(),

                                Select::make('domain_tag_ids')
                                    ->label(__('Kategori'))
                                    ->placeholder(__('Any Category'))
                                    ->searchable()
                                    ->multiple()
                                    ->getSearchResultsUsing(fn (string $search): array => $this->searchTagOptions(TagType::Domain, $search))
                                    ->getOptionLabelsUsing(fn (array $values): array => $this->tagOptionLabels(TagType::Domain, $values))
                                    ->live(),

                                Select::make('topic_ids')
                                    ->label(__('Bidang Ilmu'))
                                    ->placeholder(__('Any Knowledge Field'))
                                    ->searchable()
                                    ->multiple()
                                    ->getSearchResultsUsing(fn (string $search): array => $this->searchTagOptions(TagType::Discipline, $search))
                                    ->getOptionLabelsUsing(fn (array $values): array => $this->tagOptionLabels(TagType::Discipline, $values))
                                    ->live(),

                                Select::make('source_tag_ids')
                                    ->label(__('Sumber Rujukan Utama'))
                                    ->placeholder(__('Pilih sumber...'))
                                    ->searchable()
                                    ->multiple()
                                    ->getSearchResultsUsing(fn (string $search): array => $this->searchTagOptions(TagType::Source, $search))
                                    ->getOptionLabelsUsing(fn (array $values): array => $this->tagOptionLabels(TagType::Source, $values))
                                    ->live(),

                                Select::make('issue_tag_ids')
                                    ->label(__('Tema / Isu'))
                                    ->placeholder(__('Pilih atau taip untuk tambah tema...'))
                                    ->searchable()
                                    ->multiple()
                                    ->getSearchResultsUsing(fn (string $search): array => $this->searchTagOptions(TagType::Issue, $search))
                                    ->getOptionLabelsUsing(fn (array $values): array => $this->tagOptionLabels(TagType::Issue, $values))
                                    ->live(),

                                Select::make('reference_ids')
                                    ->label(__('Rujukan Kitab/Buku'))
                                    ->placeholder(__('Cari atau pilih rujukan...'))
                                    ->searchable()
                                    ->multiple()
                                    ->getSearchResultsUsing(fn (string $search): array => $this->searchReferenceOptions($search))
                                    ->getOptionLabelsUsing(fn (array $values): array => $this->referenceOptionLabels($values))
                                    ->live(),
                            ]),

                        Section::make(__('Event Settings'))
                            ->extraAttributes(['class' => 'mi-advanced-filter-group'])
                            ->description(__('Filter by event type, format, age group, gender, and language.'))
                            ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                            ->schema([
                                Select::make('event_type')
                                    ->label(__('Event Type'))
                                    ->placeholder(__('Any Type'))
                                    ->searchable()
                                    ->multiple()
                                    ->options(fn (): array => collect(EventType::cases())
                                        ->mapToGroups(fn (EventType $type): array => [$type->getGroup() => [$type->value => $type->getLabel()]])
                                        ->map(fn (Collection $group): array => $group->collapse()->all())
                                        ->toArray())
                                    ->live(),

                                Select::make('event_format')
                                    ->label(__('Format Majlis'))
                                    ->placeholder(__('Any Format'))
                                    ->options(collect(EventFormat::cases())
                                        ->mapWithKeys(fn (EventFormat $format): array => [$format->value => $format->getLabel()])
                                        ->all())
                                    ->multiple()
                                    ->live(),

                                Select::make('gender')
                                    ->label(__('Gender'))
                                    ->placeholder(__('Any'))
                                    ->options(collect(EventGenderRestriction::cases())
                                        ->mapWithKeys(fn (EventGenderRestriction $gender): array => [$gender->value => $gender->getLabel()])
                                        ->all())
                                    ->live(),

                                Select::make('age_group')
                                    ->label(__('Age Group'))
                                    ->placeholder(__('Any Age Group'))
                                    ->options(collect(EventAgeGroup::cases())
                                        ->mapWithKeys(fn (EventAgeGroup $age): array => [$age->value => $age->getLabel()])
                                        ->all())
                                    ->multiple()
                                    ->live()
                                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                                        $ageGroups = $this->normalizeStringArray($state);

                                        if (
                                            in_array(EventAgeGroup::Children->value, $ageGroups, true)
                                            || in_array(EventAgeGroup::AllAges->value, $ageGroups, true)
                                        ) {
                                            $set('children_allowed', true);
                                        }
                                    }),

                                Select::make('language_codes')
                                    ->label(__('Bahasa'))
                                    ->helperText(__('Bahasa yang digunakan dalam majlis.'))
                                    ->placeholder(__('Any Language'))
                                    ->multiple()
                                    ->searchable()
                                    ->options(fn (): array => $this->languageOptions())
                                    ->live(),
                            ]),

                        Section::make(__('Audience'))
                            ->extraAttributes(['class' => 'mi-advanced-filter-group'])
                            ->description(__('Set attendance restrictions and age targeting.'))
                            ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                            ->schema([
                                Select::make('children_allowed')
                                    ->label(__('Children Allowed'))
                                    ->placeholder(__('Any'))
                                    ->options(['1' => __('Yes'), '0' => __('No')])
                                    ->live(),

                                Select::make('is_muslim_only')
                                    ->label(__('Muslim Only'))
                                    ->placeholder(__('Any'))
                                    ->options(['1' => __('Yes'), '0' => __('No')])
                                    ->live(),
                            ]),

                        Section::make(__('Links & Visibility'))
                            ->extraAttributes(['class' => 'mi-advanced-filter-group'])
                            ->description(__('Filter events by event and live URL availability.'))
                            ->columns(['default' => 1, 'md' => 2])
                            ->schema([
                                Select::make('has_event_url')
                                    ->label(__('Event URL'))
                                    ->placeholder(__('Any'))
                                    ->options(['1' => __('Has URL'), '0' => __('No URL')])
                                    ->live(),

                                Select::make('has_live_url')
                                    ->label(__('Live URL'))
                                    ->placeholder(__('Any'))
                                    ->options(['1' => __('Has Live URL'), '0' => __('No Live URL')])
                                    ->live(),
                            ]),
                    ]),
            ]);
    }

    public function updatedFilterData(): void
    {
        $normalized = $this->normalizedFilterData($this->filterData);

        $this->filterData = $normalized;
        $this->dispatch('event-filters-updated', filters: $normalized);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    #[On('event-filters-synced')]
    public function syncFilters(array $filters): void
    {
        $this->filterData = $this->normalizedFilterData($filters);
    }

    /**
     * @return Collection<int, State>
     */
    #[Computed]
    public function states(): Collection
    {
        $countryId = $this->filterData['country_id'] ?? null;

        if (! filled($countryId)) {
            return collect();
        }

        return app(SafeModelCache::class)->rememberCollection(
            key: 'states_all_v1',
            ttl: 3600,
            query: State::query()
                ->orderBy('name'),
        )
            ->where('country_id', (int) $countryId)
            ->values();
    }

    /**
     * @return array<string, string>
     */
    public function languageOptions(): array
    {
        return cache()->remember('event_filter_languages_v2', 3600, function (): array {
            $preferredOrder = ['ms', 'ar', 'en', 'id', 'zh', 'ta', 'jv'];
            $preferredLabels = [
                'ms' => 'Bahasa Melayu',
                'ar' => 'Bahasa Arab',
                'en' => 'Bahasa Inggeris',
                'id' => 'Bahasa Indonesia',
                'zh' => 'Bahasa Cina',
                'ta' => 'Bahasa Tamil',
                'jv' => 'Bahasa Jawa',
            ];

            return Language::query()
                ->whereIn('code', $preferredOrder)
                ->get(['code', 'name'])
                ->sortBy(fn (Language $language): int|false => array_search((string) $language->code, $preferredOrder, true))
                ->mapWithKeys(function (Language $language) use ($preferredLabels): array {
                    $code = (string) $language->code;
                    $label = $preferredLabels[$code] ?? (string) ($language->name ?? strtoupper($code));

                    return [$code => $label];
                })
                ->all();
        });
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    public function speakerOptionLabels(array $values): array
    {
        if ($values === []) {
            return [];
        }

        return $this->pluckOptions(
            Speaker::query()->whereIn('status', ['verified', 'pending'])->where('is_active', true)->whereIn('id', $values),
            'name',
            count($values),
        );
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    public function tagOptionLabels(TagType $type, array $values): array
    {
        if ($values === []) {
            return [];
        }

        return $this->pluckOptions(
            Tag::query()->where('type', $type->value)->whereIn('status', ['verified', 'pending'])->whereIn('id', $values)->ordered(),
            'name',
            count($values),
        );
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    public function referenceOptionLabels(array $values): array
    {
        if ($values === []) {
            return [];
        }

        return $this->referenceOptionsFromQuery(
            Reference::query()->where('is_active', true)->whereIn('id', $values)->orderBy('title'),
            count($values),
        );
    }

    public function institutionOptionLabel(string $value): ?string
    {
        return Institution::query()
            ->whereIn('status', ['verified', 'pending'])
            ->where('is_active', true)
            ->whereKey($value)
            ->first(['id', 'name', 'nickname'])
            ?->display_name;
    }

    public function venueOptionLabel(string $value): ?string
    {
        return Venue::query()->whereIn('status', ['verified', 'pending'])->where('is_active', true)->whereKey($value)->value('name');
    }

    public function render(): View
    {
        return view('livewire.pages.events.advanced-filters-panel');
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultFilterData(): array
    {
        return [
            'search' => null,
            'country_id' => (string) app(PreferredCountryResolver::class)->resolveId(),
            'state_id' => null,
            'district_id' => null,
            'subdistrict_id' => null,
            'language_codes' => [],
            'event_type' => [],
            'gender' => null,
            'age_group' => [],
            'children_allowed' => null,
            'is_muslim_only' => null,
            'institution_id' => null,
            'venue_id' => null,
            'speaker_ids' => [],
            'key_person_roles' => [],
            'person_in_charge_ids' => [],
            'person_in_charge_search' => null,
            'moderator_ids' => [],
            'imam_ids' => [],
            'khatib_ids' => [],
            'bilal_ids' => [],
            'topic_ids' => [],
            'domain_tag_ids' => [],
            'source_tag_ids' => [],
            'issue_tag_ids' => [],
            'reference_ids' => [],
            'starts_after' => null,
            'starts_before' => null,
            'time_scope' => 'upcoming',
            'prayer_time' => null,
            'timing_mode' => null,
            'starts_time_from' => null,
            'starts_time_until' => null,
            'event_format' => [],
            'has_event_url' => null,
            'has_live_url' => null,
            'has_end_time' => null,
            'lat' => null,
            'lng' => null,
            'radius_km' => 15,
            'sort' => 'time',
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalizedFilterData(array $raw): array
    {
        $defaults = $this->defaultFilterData();
        $normalized = array_replace($defaults, $raw);

        $languageCodes = $this->normalizeStringArray($normalized['language_codes'] ?? []);
        $timeScope = (string) ($normalized['time_scope'] ?? $defaults['time_scope']);
        $sort = (string) ($normalized['sort'] ?? $defaults['sort']);
        $timingMode = (string) ($normalized['timing_mode'] ?? '');

        if (! in_array($timeScope, ['upcoming', 'past', 'all'], true)) {
            $timeScope = (string) $defaults['time_scope'];
        }

        if (! in_array($sort, ['time', 'relevance', 'distance'], true)) {
            $sort = (string) $defaults['sort'];
        }

        if (! in_array($timingMode, [TimingMode::Absolute->value, TimingMode::PrayerRelative->value], true)) {
            $timingMode = '';
        }

        $startsTimeFrom = $this->normalizeTimeString($normalized['starts_time_from'] ?? null);
        $startsTimeUntil = $this->normalizeTimeString($normalized['starts_time_until'] ?? null);
        $prayerTime = filled($normalized['prayer_time']) ? (string) $normalized['prayer_time'] : null;

        if ($timingMode !== TimingMode::Absolute->value) {
            $startsTimeFrom = null;
            $startsTimeUntil = null;
        }

        if ($timingMode === TimingMode::Absolute->value) {
            $prayerTime = null;
        }

        return [
            'search' => filled($normalized['search']) ? trim((string) $normalized['search']) : null,
            'country_id' => filled($normalized['country_id']) ? (string) $normalized['country_id'] : $defaults['country_id'],
            'state_id' => filled($normalized['state_id']) ? (string) $normalized['state_id'] : null,
            'district_id' => filled($normalized['district_id']) ? (string) $normalized['district_id'] : null,
            'subdistrict_id' => filled($normalized['subdistrict_id']) ? (string) $normalized['subdistrict_id'] : null,
            'language_codes' => $languageCodes,
            'event_type' => $this->normalizeStringArray($normalized['event_type'] ?? []),
            'gender' => filled($normalized['gender']) ? (string) $normalized['gender'] : null,
            'age_group' => $this->normalizeStringArray($normalized['age_group'] ?? []),
            'children_allowed' => $this->normalizeNullableBoolean($normalized['children_allowed'] ?? null),
            'is_muslim_only' => $this->normalizeNullableBoolean($normalized['is_muslim_only'] ?? null),
            'institution_id' => filled($normalized['institution_id']) ? (string) $normalized['institution_id'] : null,
            'venue_id' => filled($normalized['venue_id']) ? (string) $normalized['venue_id'] : null,
            'speaker_ids' => $this->normalizeStringArray($normalized['speaker_ids'] ?? []),
            'key_person_roles' => $this->normalizeStringArray($normalized['key_person_roles'] ?? []),
            'person_in_charge_ids' => $this->normalizeStringArray($normalized['person_in_charge_ids'] ?? []),
            'person_in_charge_search' => filled($normalized['person_in_charge_search'] ?? null) ? trim((string) $normalized['person_in_charge_search']) : null,
            'moderator_ids' => $this->normalizeStringArray($normalized['moderator_ids'] ?? []),
            'imam_ids' => $this->normalizeStringArray($normalized['imam_ids'] ?? []),
            'khatib_ids' => $this->normalizeStringArray($normalized['khatib_ids'] ?? []),
            'bilal_ids' => $this->normalizeStringArray($normalized['bilal_ids'] ?? []),
            'topic_ids' => $this->normalizeStringArray($normalized['topic_ids'] ?? []),
            'domain_tag_ids' => $this->normalizeStringArray($normalized['domain_tag_ids'] ?? []),
            'source_tag_ids' => $this->normalizeStringArray($normalized['source_tag_ids'] ?? []),
            'issue_tag_ids' => $this->normalizeStringArray($normalized['issue_tag_ids'] ?? []),
            'reference_ids' => $this->normalizeStringArray($normalized['reference_ids'] ?? []),
            'starts_after' => filled($normalized['starts_after']) ? (string) $normalized['starts_after'] : null,
            'starts_before' => filled($normalized['starts_before']) ? (string) $normalized['starts_before'] : null,
            'time_scope' => $timeScope,
            'prayer_time' => $prayerTime,
            'timing_mode' => $timingMode !== '' ? $timingMode : null,
            'starts_time_from' => $startsTimeFrom,
            'starts_time_until' => $startsTimeUntil,
            'event_format' => $this->normalizeStringArray($normalized['event_format'] ?? []),
            'has_event_url' => $this->normalizeNullableBoolean($normalized['has_event_url'] ?? null),
            'has_live_url' => $this->normalizeNullableBoolean($normalized['has_live_url'] ?? null),
            'has_end_time' => $this->normalizeNullableBoolean($normalized['has_end_time'] ?? null),
            'lat' => filled($normalized['lat']) ? (string) $normalized['lat'] : null,
            'lng' => filled($normalized['lng']) ? (string) $normalized['lng'] : null,
            'radius_km' => max(1, min(1000, (int) ($normalized['radius_km'] ?? $defaults['radius_km']))),
            'sort' => $sort,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeStringArray(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter(array_map(strval(...), $values), static fn (string $item): bool => $item !== ''));
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeTimeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        if ($normalized === '') {
            return null;
        }

        try {
            return now()->setTimeFromTimeString($normalized)->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeNullableBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [1, '1', 'true', 'on', 'yes'], true)) {
            return true;
        }

        if (in_array($value, [0, '0', 'false', 'off', 'no'], true)) {
            return false;
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function searchInstitutionOptions(?string $countryId, ?string $stateId, ?string $districtId, ?string $subdistrictId, string $search = ''): array
    {
        $query = Institution::query()->whereIn('status', ['verified', 'pending'])->where('is_active', true);

        $this->applyAddressLocationFilters($query, $countryId, $stateId, $districtId, $subdistrictId);
        $query->searchNameOrNickname($search);

        return $this->institutionOptionsFromQuery($query->orderBy('name'), 50);
    }

    /**
     * @return array<string, string>
     */
    private function searchVenueOptions(?string $countryId, ?string $stateId, ?string $districtId, ?string $subdistrictId, string $search = ''): array
    {
        $query = Venue::query()->whereIn('status', ['verified', 'pending'])->where('is_active', true);

        $this->applyAddressLocationFilters($query, $countryId, $stateId, $districtId, $subdistrictId);
        $this->applySearchConstraint($query, 'name', $search);

        return $this->pluckOptions($query->orderBy('name'), 'name', 50);
    }

    /**
     * @return array<string, string>
     */
    private function searchSpeakerOptions(string $search): array
    {
        return $this->pluckOptions(
            Speaker::query()
                ->whereIn('status', ['verified', 'pending'])
                ->where('is_active', true)
                ->tap(fn (Builder $query): Builder => $this->applySearchConstraint($query, 'name', $search))
                ->orderBy('name'),
            'name',
            50,
        );
    }

    /**
     * @return array<string, string>
     */
    private function searchTagOptions(TagType $type, string $search): array
    {
        return $this->pluckOptions(
            Tag::query()
                ->where('type', $type->value)
                ->whereIn('status', ['verified', 'pending'])
                ->tap(fn (Builder $query): Builder => $this->applySearchConstraint($query, 'name', $search))
                ->ordered(),
            'name',
            50,
        );
    }

    /**
     * @return array<string, string>
     */
    private function searchReferenceOptions(string $search): array
    {
        $query = Reference::query()
            ->where('is_active', true)
            ->tap(fn (Builder $query): Builder => $this->applyReferenceSearchConstraint($query, $search))
            ->orderBy('title');

        return $this->referenceOptionsFromQuery($query, 50);
    }

    /**
     * @param  Builder<Reference>  $query
     * @return array<string, string>
     */
    private function referenceOptionsFromQuery(Builder $query, int $limit): array
    {
        /** @var Collection<int, Reference> $references */
        $references = $query
            ->limit($limit)
            ->get(['id', 'title', 'parent_reference_id', 'part_type', 'part_number', 'part_label']);

        return $references
            ->mapWithKeys(fn (Reference $reference): array => [(string) $reference->id => $reference->displayTitle()])
            ->all();
    }

    /**
     * @param  Builder<Reference>  $query
     * @return Builder<Reference>
     */
    private function applyReferenceSearchConstraint(Builder $query, string $search): Builder
    {
        $normalizedSearch = trim($search);

        if ($normalizedSearch === '') {
            return $query;
        }

        $operator = $this->databaseLikeOperator();

        return $query->where(function (Builder $referenceQuery) use ($operator, $normalizedSearch): void {
            $referenceQuery
                ->where('title', $operator, "%{$normalizedSearch}%")
                ->orWhere('part_label', $operator, "%{$normalizedSearch}%")
                ->orWhere('part_number', $operator, "%{$normalizedSearch}%");
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return array<string, string>
     */
    private function pluckOptions(Builder $query, string $labelColumn, int $limit): array
    {
        return $query
            ->limit($limit)
            ->pluck($labelColumn, 'id')
            ->mapWithKeys(fn (string $label, mixed $id): array => [(string) $id => $label])
            ->all();
    }

    /**
     * @param  Builder<Institution>  $query
     * @return array<string, string>
     */
    private function institutionOptionsFromQuery(Builder $query, int $limit): array
    {
        /** @var Collection<int, Institution> $institutions */
        $institutions = $query
            ->limit($limit)
            ->get(['id', 'name', 'nickname']);

        return $institutions
            ->mapWithKeys(fn (Institution $institution): array => [(string) $institution->id => $institution->display_name])
            ->all();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function applySearchConstraint(Builder $query, string $column, string $search): Builder
    {
        $normalizedSearch = trim($search);

        if ($normalizedSearch === '') {
            return $query;
        }

        return $query->where($column, $this->databaseLikeOperator(), "%{$normalizedSearch}%");
    }

    private function databaseLikeOperator(): string
    {
        return config('database.default') === 'pgsql' ? 'ILIKE' : 'LIKE';
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyAddressLocationFilters(Builder $query, ?string $countryId, ?string $stateId, ?string $districtId, ?string $subdistrictId): void
    {
        if (! filled($countryId) && ! filled($stateId) && ! filled($districtId) && ! filled($subdistrictId)) {
            return;
        }

        $query->whereHas('address', function (Builder $addressQuery) use ($countryId, $stateId, $districtId, $subdistrictId): void {
            if (filled($countryId)) {
                $addressQuery->where('country_id', $countryId);
            }

            if (filled($stateId)) {
                $addressQuery->where('state_id', $stateId);
            }

            if (filled($districtId)) {
                $addressQuery->where('district_id', $districtId);
            }

            if (filled($subdistrictId)) {
                $addressQuery->where('subdistrict_id', $subdistrictId);
            }
        });
    }
}
