<?php

namespace App\Livewire\Pages\Events;

use App\Actions\Events\SaveEventAction;
use App\Actions\Events\UnsaveEventAction;
use App\Enums\EventAgeGroup;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\TagType;
use App\Enums\TimingMode;
use App\Models\Country;
use App\Models\District;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\State;
use App\Models\Subdistrict;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use App\Services\EventSearchService;
use App\Support\Auth\IntendedRedirect;
use App\Support\Cache\SafeModelCache;
use App\Support\Location\FederalTerritoryLocation;
use App\Support\Location\PreferredCountryResolver;
use App\Support\Location\PublicGeolocationPermission;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Nnjeim\World\Models\Language;

#[Layout('layouts.app')]
#[Title('Upcoming Events')]
class Index extends Component implements HasForms
{
    use InteractsWithForms;
    use WithPagination;

    #[Url]
    public ?string $search = null;

    #[Url]
    public ?string $country_id = null;

    #[Url]
    public ?string $state_id = null;

    #[Url]
    public ?string $district_id = null;

    #[Url]
    public ?string $subdistrict_id = null;

    /**
     * @var list<string>
     */
    #[Url]
    public array $language_codes = [];

    /**
     * @var list<string>|string|null
     */
    #[Url]
    public array|string|null $event_type = [];

    #[Url]
    public ?string $gender = null;

    /**
     * @var list<string>
     */
    #[Url]
    public array $age_group = [];

    #[Url]
    public ?bool $children_allowed = null;

    #[Url]
    public ?bool $is_muslim_only = null;

    #[Url]
    public ?string $institution_id = null;

    #[Url]
    public ?string $venue_id = null;

    /**
     * @var list<string>
     */
    #[Url]
    public array $speaker_ids = [];

    /**
     * @var list<string>
     */
    #[Url]
    public array $key_person_roles = [];

    /**
     * @var list<string>
     */
    #[Url]
    public array $moderator_ids = [];

    /**
     * @var list<string>
     */
    #[Url]
    public array $person_in_charge_ids = [];

    #[Url]
    public ?string $person_in_charge_search = null;

    /**
     * @var list<string>
     */
    #[Url]
    public array $imam_ids = [];

    /**
     * @var list<string>
     */
    #[Url]
    public array $khatib_ids = [];

    /**
     * @var list<string>
     */
    #[Url]
    public array $bilal_ids = [];

    /**
     * @var list<string>
     */
    #[Url]
    public array $topic_ids = [];

    /**
     * @var list<string>
     */
    #[Url]
    public array $domain_tag_ids = [];

    /**
     * @var list<string>
     */
    #[Url]
    public array $source_tag_ids = [];

    /**
     * @var list<string>
     */
    #[Url]
    public array $issue_tag_ids = [];

    /**
     * @var list<string>
     */
    #[Url]
    public array $reference_ids = [];

    #[Url]
    public ?string $starts_after = null;

    #[Url]
    public ?string $starts_before = null;

    #[Url]
    public ?string $time_scope = null;

    #[Url]
    public ?string $prayer_time = null;

    #[Url]
    public ?string $timing_mode = null;

    #[Url]
    public ?string $starts_time_from = null;

    #[Url]
    public ?string $starts_time_until = null;

    /**
     * @var list<string>|string|null
     */
    #[Url]
    public array|string|null $event_format = [];

    #[Url]
    public ?bool $has_event_url = null;

    #[Url]
    public ?bool $has_live_url = null;

    #[Url]
    public ?bool $has_end_time = null;

    #[Url]
    public ?string $lat = null;

    #[Url]
    public ?string $lng = null;

    #[Url]
    public int $radius_km = 15;

    #[Url]
    public string $sort = 'time';

    #[Url]
    public bool $search_include_institutions = true;

    #[Url]
    public bool $search_include_speakers = true;

    #[Url]
    public bool $search_include_references = true;

    /**
     * @var list<string>
     */
    #[Url]
    public array $reference_author_search = [];

    /**
     * @var array<string, mixed>
     */
    public array $filterData = [];

    public function mount(): void
    {
        $normalized = $this->normalizedUrlState();

        $this->fillPublicPropertiesFromFilters($normalized);
        $this->filterData = $normalized;
    }

    public function showsGeolocationControls(): bool
    {
        return app(PublicGeolocationPermission::class)->isGranted();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    #[On('event-filters-updated')]
    public function syncAdvancedFilters(array $filters): void
    {
        $normalized = $this->normalizedFilterData($filters);

        $this->fillPublicPropertiesFromFilters($normalized);
        $this->filterData = $normalized;
        $this->resetPage();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('filterData')
            ->schema([
                Section::make(__('Masa'))
                    ->extraAttributes(['class' => 'mi-advanced-filter-group'])
                    ->schema([
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
                                ->all()
                            )
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

                Section::make(__('Lokasi majlis'))
                    ->extraAttributes(['class' => 'mi-advanced-filter-group'])
                    ->schema([
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
                    ]),

                Section::make(__('Penceramah & kandungan'))
                    ->extraAttributes(['class' => 'mi-advanced-filter-group'])
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

                        Select::make('reference_author_search')
                            ->label(__('Pengarang Rujukan'))
                            ->placeholder(__('Cari atau pilih pengarang...'))
                            ->searchable()
                            ->multiple()
                            ->getSearchResultsUsing(fn (string $search): array => $this->searchReferenceAuthorOptions($search))
                            ->getOptionLabelsUsing(fn (array $values): array => $this->referenceAuthorOptionLabels($values))
                            ->live(),
                    ]),

                Section::make(__('Untuk siapa'))
                    ->extraAttributes(['class' => 'mi-advanced-filter-group'])
                    ->schema([
                        Select::make('gender')
                            ->label(__('Gender'))
                            ->placeholder(__('Any'))
                            ->options(collect(EventGenderRestriction::cases())
                                ->mapWithKeys(fn (EventGenderRestriction $gender): array => [$gender->value => $gender->getLabel()])
                                ->all()
                            )
                            ->live(),

                        Select::make('age_group')
                            ->label(__('Age Group'))
                            ->placeholder(__('Any Age Group'))
                            ->options(collect(EventAgeGroup::cases())
                                ->mapWithKeys(fn (EventAgeGroup $age): array => [$age->value => $age->getLabel()])
                                ->all()
                            )
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

                        Select::make('children_allowed')
                            ->label(__('Children Allowed'))
                            ->placeholder(__('Any'))
                            ->options([
                                '1' => __('Yes'),
                                '0' => __('No'),
                            ])
                            ->live(),

                        Select::make('is_muslim_only')
                            ->label(__('Muslim Only'))
                            ->placeholder(__('Any'))
                            ->options([
                                '1' => __('Yes'),
                                '0' => __('No'),
                            ])
                            ->live(),
                    ]),

                Section::make(__('Pautan & siaran'))
                    ->extraAttributes(['class' => 'mi-advanced-filter-group'])
                    ->schema([
                        Select::make('has_event_url')
                            ->label(__('Event URL'))
                            ->placeholder(__('Any'))
                            ->options([
                                '1' => __('Has URL'),
                                '0' => __('No URL'),
                            ])
                            ->live(),

                        Select::make('has_live_url')
                            ->label(__('Live URL'))
                            ->placeholder(__('Any'))
                            ->options([
                                '1' => __('Has Live URL'),
                                '0' => __('No Live URL'),
                            ])
                            ->live(),
                    ]),
            ]);
    }

    public function updatedFilterData(): void
    {
        $normalized = $this->normalizedFilterData($this->filterData);

        $this->fillPublicPropertiesFromFilters($normalized);
        $this->resetPage();
    }

    public function setLocation(float $lat, float $lng): void
    {
        $this->lat = (string) $lat;
        $this->lng = (string) $lng;
        $this->radius_km = 15;
        $this->sort = 'distance';

        $this->filterData['lat'] = $this->lat;
        $this->filterData['lng'] = $this->lng;
        $this->filterData['radius_km'] = $this->radius_km;
        $this->filterData['sort'] = $this->sort;

        $this->dispatch('event-filters-synced', filters: $this->filterData);
        $this->resetPage();
    }

    public function clearLocation(): void
    {
        $this->lat = null;
        $this->lng = null;

        $defaultSort = $this->sort === 'distance' ? 'time' : $this->sort;
        $this->sort = $defaultSort;

        $this->filterData['lat'] = null;
        $this->filterData['lng'] = null;
        $this->filterData['sort'] = $defaultSort;

        $this->dispatch('event-filters-synced', filters: $this->filterData);
        $this->resetPage();
    }

    public function clearAllFilters(): void
    {
        $defaults = $this->defaultFilterData();

        $this->fillPublicPropertiesFromFilters($defaults);
        $this->filterData = $defaults;

        $this->dispatch('event-filters-synced', filters: $defaults);
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = null;
        $this->filterData['search'] = null;
        $this->resetPage();
    }

    public function setSort(string $sort): void
    {
        if (! in_array($sort, ['time', 'relevance', 'distance'], true)) {
            return;
        }

        if ($sort === 'distance' && (! filled($this->lat) || ! filled($this->lng))) {
            return;
        }

        $this->sort = $sort;
        $this->filterData['sort'] = $sort;

        $this->resetPage();
    }

    public function toggleSave(string $eventId): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirect(IntendedRedirect::loginUrl(request()->fullUrl()), navigate: true);

            return;
        }

        $event = Event::query()
            ->active()
            ->whereKey($eventId)
            ->first();

        if (! $event instanceof Event || ! in_array((string) $event->status, Event::ENGAGEABLE_STATUSES, true)) {
            return;
        }

        $isSaved = $user->savedEvents()
            ->where('event_id', $event->id)
            ->exists();

        if ($isSaved) {
            app(UnsaveEventAction::class)->handle($event->id, $user);

            return;
        }

        app(SaveEventAction::class)->handle($event, $user, request());
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function savedEventIds(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $eventIds = [];

        foreach ($this->events()->items() as $event) {
            if ($event instanceof Event) {
                $eventIds[] = (string) $event->getKey();
            }
        }

        if ($eventIds === []) {
            return [];
        }

        return $user->savedEvents()
            ->whereIn('events.id', $eventIds)
            ->pluck('events.id')
            ->map(fn (mixed $eventId): string => (string) $eventId)
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Country>
     */
    #[Computed]
    public function countries(): Collection
    {
        return app(SafeModelCache::class)->rememberCollection(
            key: 'countries_all_v1',
            ttl: 3600,
            query: Country::query()
                ->orderBy('name')
                ->select(['id', 'name', 'iso2']),
        );
    }

    /**
     * @return Collection<int, State>
     */
    #[Computed]
    public function states(): Collection
    {
        if (! filled($this->country_id)) {
            return collect();
        }

        return app(SafeModelCache::class)->rememberCollection(
            key: 'states_all_v1',
            ttl: 3600,
            query: State::query()
                ->orderBy('name'),
        )
            ->where('country_id', (int) $this->country_id)
            ->values();
    }

    /**
     * @return Collection<int, District>
     */
    #[Computed]
    public function districts(): Collection
    {
        $stateId = $this->state_id;

        if (! filled($stateId) || FederalTerritoryLocation::isFederalTerritoryStateId($stateId)) {
            return collect();
        }

        return District::query()
            ->where('state_id', $stateId)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Subdistrict>
     */
    #[Computed]
    public function subdistricts(): Collection
    {
        if (FederalTerritoryLocation::isFederalTerritoryStateId($this->state_id)) {
            return Subdistrict::query()
                ->where('state_id', $this->state_id)
                ->whereNull('district_id')
                ->orderBy('name')
                ->get();
        }

        $districtId = $this->district_id;

        if (! filled($districtId)) {
            return collect();
        }

        return Subdistrict::query()
            ->where('district_id', $districtId)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Tag>
     */
    #[Computed]
    public function disciplines(): Collection
    {
        return app(SafeModelCache::class)->rememberCollection(
            key: 'events_disciplines_'.app()->getLocale().'_v2',
            ttl: 300,
            query: Tag::query()
                ->where('type', TagType::Discipline->value)
                ->whereIn('status', ['verified', 'pending'])
                ->ordered(),
        );
    }

    /**
     * @return Collection<int, Tag>
     */
    #[Computed]
    public function domains(): Collection
    {
        return app(SafeModelCache::class)->rememberCollection(
            key: 'events_domains_'.app()->getLocale().'_v2',
            ttl: 300,
            query: Tag::query()
                ->where('type', TagType::Domain->value)
                ->whereIn('status', ['verified', 'pending'])
                ->ordered(),
        );
    }

    /**
     * @return Collection<int, Tag>
     */
    #[Computed]
    public function sources(): Collection
    {
        return app(SafeModelCache::class)->rememberCollection(
            key: 'events_sources_'.app()->getLocale().'_v2',
            ttl: 300,
            query: Tag::query()
                ->where('type', TagType::Source->value)
                ->whereIn('status', ['verified', 'pending'])
                ->ordered(),
        );
    }

    /**
     * @return Collection<int, Tag>
     */
    #[Computed]
    public function issues(): Collection
    {
        return app(SafeModelCache::class)->rememberCollection(
            key: 'events_issues_'.app()->getLocale().'_v2',
            ttl: 300,
            query: Tag::query()
                ->where('type', TagType::Issue->value)
                ->whereIn('status', ['verified', 'pending'])
                ->ordered(),
        );
    }

    /**
     * @return Collection<int, Reference>
     */
    #[Computed]
    public function references(): Collection
    {
        return app(SafeModelCache::class)->rememberCollection(
            key: 'events_references_'.app()->getLocale().'_v2',
            ttl: 300,
            query: Reference::query()
                ->where('is_active', true)
                ->orderBy('title')
                ->limit(400)
                ->select(['id', 'title']),
        );
    }

    /**
     * @return Collection<int, Institution>
     */
    #[Computed]
    public function institutions(): Collection
    {
        return app(SafeModelCache::class)->rememberCollection(
            key: 'events_institutions_'.app()->getLocale().'_v2',
            ttl: 300,
            query: Institution::query()
                ->whereIn('status', ['verified', 'pending'])
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(400)
                ->select(['id', 'name', 'nickname']),
        );
    }

    /**
     * @return Collection<int, Venue>
     */
    #[Computed]
    public function venues(): Collection
    {
        return app(SafeModelCache::class)->rememberCollection(
            key: 'events_venues_'.app()->getLocale().'_v2',
            ttl: 300,
            query: Venue::query()
                ->whereIn('status', ['verified', 'pending'])
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(500)
                ->select(['id', 'name']),
        );
    }

    /**
     * @return array<string, string>
     */
    private function searchInstitutionOptions(?string $countryId, ?string $stateId, ?string $districtId, ?string $subdistrictId, string $search = ''): array
    {
        $query = Institution::query()
            ->whereIn('status', ['verified', 'pending'])
            ->where('is_active', true);

        $this->applyAddressLocationFilters($query, $countryId, $stateId, $districtId, $subdistrictId);
        $query->searchNameOrNickname($search);

        return $this->institutionOptionsFromQuery($query->orderBy('name'), 50);
    }

    /**
     * @return array<string, string>
     */
    private function searchVenueOptions(?string $countryId, ?string $stateId, ?string $districtId, ?string $subdistrictId, string $search = ''): array
    {
        $query = Venue::query()
            ->whereIn('status', ['verified', 'pending'])
            ->where('is_active', true);

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
     * @param  list<string>  $values
     * @return array<string, string>
     */
    public function speakerOptionLabels(array $values): array
    {
        if ($values === []) {
            return [];
        }

        return $this->pluckOptions(
            Speaker::query()
                ->whereIn('status', ['verified', 'pending'])
                ->where('is_active', true)
                ->whereIn('id', $values),
            'name',
            count($values),
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
     * @param  list<string>  $values
     * @return array<string, string>
     */
    public function tagOptionLabels(TagType $type, array $values): array
    {
        if ($values === []) {
            return [];
        }

        return $this->pluckOptions(
            Tag::query()
                ->where('type', $type->value)
                ->whereIn('status', ['verified', 'pending'])
                ->whereIn('id', $values)
                ->ordered(),
            'name',
            count($values),
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
     * @param  list<string>  $values
     * @return array<string, string>
     */
    public function referenceOptionLabels(array $values): array
    {
        if ($values === []) {
            return [];
        }

        return $this->referenceOptionsFromQuery(
            Reference::query()
                ->where('is_active', true)
                ->whereIn('id', $values)
                ->orderBy('title'),
            count($values),
        );
    }

    /**
     * @return array<string, string>
     */
    private function searchReferenceAuthorOptions(string $search): array
    {
        $query = Reference::query()
            ->where('is_active', true)
            ->whereNotNull('author')
            ->where('author', '!=', '')
            ->orderBy('author');

        $normalizedSearch = trim($search);

        if ($normalizedSearch !== '') {
            $query->where('author', $this->databaseLikeOperator(), "%{$normalizedSearch}%");
        }

        return $query
            ->limit(50)
            ->pluck('author', 'author')
            ->mapWithKeys(fn (string $author): array => [$author => $author])
            ->all();
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    public function referenceAuthorOptionLabels(array $values): array
    {
        if ($values === []) {
            return [];
        }

        return Reference::query()
            ->where('is_active', true)
            ->whereIn('author', $values)
            ->whereNotNull('author')
            ->where('author', '!=', '')
            ->orderBy('author')
            ->limit(count($values))
            ->pluck('author', 'author')
            ->mapWithKeys(fn (string $author): array => [$author => $author])
            ->all();
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
        return Venue::query()
            ->whereIn('status', ['verified', 'pending'])
            ->where('is_active', true)
            ->whereKey($value)
            ->value('name');
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
    private function applyAddressLocationFilters(
        Builder $query,
        ?string $countryId,
        ?string $stateId,
        ?string $districtId,
        ?string $subdistrictId
    ): void {
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

    /**
     * @return Collection<int, Speaker>
     */
    #[Computed]
    public function speakers(): Collection
    {
        return app(SafeModelCache::class)->rememberCollection(
            key: 'events_speakers_'.app()->getLocale().'_v2',
            ttl: 300,
            query: Speaker::query()
                ->whereIn('status', ['verified', 'pending'])
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(500)
                ->select(['id', 'name']),
        );
    }

    /**
     * @return LengthAwarePaginator<int, Event>
     */
    #[Computed]
    public function events(): LengthAwarePaginator
    {
        $filters = $this->normalizedUrlState();

        $searchFilters = [
            'country_id' => $filters['country_id'],
            'state_id' => $filters['state_id'],
            'district_id' => $filters['district_id'],
            'subdistrict_id' => $filters['subdistrict_id'],
            'language_codes' => $filters['language_codes'],
            'event_type' => $filters['event_type'],
            'gender' => $filters['gender'],
            'age_group' => $filters['age_group'],
            'children_allowed' => $filters['children_allowed'],
            'is_muslim_only' => $filters['is_muslim_only'],
            'institution_id' => $filters['institution_id'],
            'venue_id' => $filters['venue_id'],
            'speaker_ids' => $filters['speaker_ids'],
            'key_person_roles' => $filters['key_person_roles'],
            'person_in_charge_ids' => $filters['person_in_charge_ids'],
            'person_in_charge_search' => $filters['person_in_charge_search'],
            'moderator_ids' => $filters['moderator_ids'],
            'imam_ids' => $filters['imam_ids'],
            'khatib_ids' => $filters['khatib_ids'],
            'bilal_ids' => $filters['bilal_ids'],
            'topic_ids' => $filters['topic_ids'],
            'domain_tag_ids' => $filters['domain_tag_ids'],
            'source_tag_ids' => $filters['source_tag_ids'],
            'issue_tag_ids' => $filters['issue_tag_ids'],
            'reference_ids' => $filters['reference_ids'],
            'starts_after' => $filters['starts_after'],
            'starts_before' => $filters['starts_before'],
            'time_scope' => $filters['time_scope'] !== 'upcoming' ? $filters['time_scope'] : null,
            'prayer_time' => $filters['prayer_time'],
            'timing_mode' => $filters['timing_mode'],
            'starts_time_from' => $filters['starts_time_from'],
            'starts_time_until' => $filters['starts_time_until'],
            'event_format' => $filters['event_format'],
            'has_event_url' => $filters['has_event_url'],
            'has_live_url' => $filters['has_live_url'],
            'has_end_time' => $filters['has_end_time'],
        ];

        $searchFilters = array_filter($searchFilters, function (mixed $value): bool {
            if ($value === null || $value === '') {
                return false;
            }

            if (is_array($value)) {
                return $value !== [];
            }

            return true;
        });

        // Boolean scope toggles must be included even when false.
        $searchFilters['search_include_institutions'] = $filters['search_include_institutions'];
        $searchFilters['search_include_speakers'] = $filters['search_include_speakers'];
        $searchFilters['search_include_references'] = $filters['search_include_references'];

        if ($filters['reference_author_search'] !== []) {
            $searchFilters['reference_author_search'] = $filters['reference_author_search'];
        }

        /** @var EventSearchService $searchService */
        $searchService = app(EventSearchService::class);

        if ($filters['lat'] !== null && $filters['lng'] !== null) {
            return $searchService->searchNearby(
                lat: (float) $filters['lat'],
                lng: (float) $filters['lng'],
                radiusKm: $filters['radius_km'],
                filters: $searchFilters,
                perPage: 12
            );
        }

        return $searchService->search(
            query: $filters['search'],
            filters: $searchFilters,
            perPage: 12,
            sort: $filters['sort']
        );
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

    public function render(): View
    {
        return view('livewire.pages.events.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultFilterData(): array
    {
        $prayerTime = filled($this->prayer_time) ? $this->prayer_time : null;

        if ($this->timing_mode === TimingMode::Absolute->value) {
            $prayerTime = null;
        }

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
            'search_include_institutions' => true,
            'search_include_speakers' => true,
            'search_include_references' => true,
            'reference_author_search' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedUrlState(): array
    {
        $defaults = $this->defaultFilterData();

        $languageCodes = $this->normalizeStringArray($this->language_codes);

        $prayerTime = filled($this->prayer_time) ? $this->prayer_time : null;

        if ($this->timing_mode === TimingMode::Absolute->value) {
            $prayerTime = null;
        }

        return [
            'search' => filled($this->search) ? trim($this->search) : null,
            'country_id' => filled($this->country_id) ? $this->country_id : $defaults['country_id'],
            'state_id' => filled($this->state_id) ? $this->state_id : null,
            'district_id' => filled($this->district_id) ? $this->district_id : null,
            'subdistrict_id' => filled($this->subdistrict_id) ? $this->subdistrict_id : null,
            'language_codes' => $languageCodes,
            'event_type' => $this->normalizeStringArray($this->event_type),
            'gender' => filled($this->gender) ? $this->gender : null,
            'age_group' => $this->normalizeStringArray($this->age_group),
            'children_allowed' => $this->normalizeNullableBoolean($this->children_allowed),
            'is_muslim_only' => $this->normalizeNullableBoolean($this->is_muslim_only),
            'institution_id' => filled($this->institution_id) ? $this->institution_id : null,
            'venue_id' => filled($this->venue_id) ? $this->venue_id : null,
            'speaker_ids' => $this->normalizeStringArray($this->speaker_ids),
            'key_person_roles' => $this->normalizeStringArray($this->key_person_roles),
            'person_in_charge_ids' => $this->normalizeStringArray($this->person_in_charge_ids),
            'person_in_charge_search' => filled($this->person_in_charge_search) ? trim($this->person_in_charge_search) : null,
            'moderator_ids' => $this->normalizeStringArray($this->moderator_ids),
            'imam_ids' => $this->normalizeStringArray($this->imam_ids),
            'khatib_ids' => $this->normalizeStringArray($this->khatib_ids),
            'bilal_ids' => $this->normalizeStringArray($this->bilal_ids),
            'topic_ids' => $this->normalizeStringArray($this->topic_ids),
            'domain_tag_ids' => $this->normalizeStringArray($this->domain_tag_ids),
            'source_tag_ids' => $this->normalizeStringArray($this->source_tag_ids),
            'issue_tag_ids' => $this->normalizeStringArray($this->issue_tag_ids),
            'reference_ids' => $this->normalizeStringArray($this->reference_ids),
            'starts_after' => filled($this->starts_after) ? $this->starts_after : null,
            'starts_before' => filled($this->starts_before) ? $this->starts_before : null,
            'time_scope' => in_array($this->time_scope, ['upcoming', 'past', 'all'], true) ? $this->time_scope : $defaults['time_scope'],
            'prayer_time' => $prayerTime,
            'timing_mode' => in_array($this->timing_mode, [TimingMode::Absolute->value, TimingMode::PrayerRelative->value], true)
                ? $this->timing_mode
                : null,
            'starts_time_from' => $this->normalizeTimeString($this->starts_time_from),
            'starts_time_until' => $this->normalizeTimeString($this->starts_time_until),
            'event_format' => $this->normalizeStringArray($this->event_format),
            'has_event_url' => $this->normalizeNullableBoolean($this->has_event_url),
            'has_live_url' => $this->normalizeNullableBoolean($this->has_live_url),
            'has_end_time' => $this->normalizeNullableBoolean($this->has_end_time),
            'lat' => filled($this->lat) ? $this->lat : null,
            'lng' => filled($this->lng) ? $this->lng : null,
            'radius_km' => max(1, min(1000, $this->radius_km)),
            'sort' => in_array($this->sort, ['time', 'relevance', 'distance'], true) ? $this->sort : $defaults['sort'],
            'search_include_institutions' => $this->search_include_institutions,
            'search_include_speakers' => $this->search_include_speakers,
            'search_include_references' => $this->search_include_references,
            'reference_author_search' => $this->normalizeStringArray($this->reference_author_search),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function fillPublicPropertiesFromFilters(array $filters): void
    {
        $this->search = $filters['search'];
        $this->country_id = $filters['country_id'];
        $this->state_id = $filters['state_id'];
        $this->district_id = $filters['district_id'];
        $this->subdistrict_id = $filters['subdistrict_id'];
        $this->language_codes = $filters['language_codes'];
        $this->event_type = $filters['event_type'];
        $this->gender = $filters['gender'];
        $this->age_group = $filters['age_group'];
        $this->children_allowed = $filters['children_allowed'];
        $this->is_muslim_only = $filters['is_muslim_only'];
        $this->institution_id = $filters['institution_id'];
        $this->venue_id = $filters['venue_id'];
        $this->speaker_ids = $filters['speaker_ids'];
        $this->key_person_roles = $filters['key_person_roles'];
        $this->person_in_charge_ids = $filters['person_in_charge_ids'];
        $this->person_in_charge_search = $filters['person_in_charge_search'];
        $this->moderator_ids = $filters['moderator_ids'];
        $this->imam_ids = $filters['imam_ids'];
        $this->khatib_ids = $filters['khatib_ids'];
        $this->bilal_ids = $filters['bilal_ids'];
        $this->topic_ids = $filters['topic_ids'];
        $this->domain_tag_ids = $filters['domain_tag_ids'];
        $this->source_tag_ids = $filters['source_tag_ids'];
        $this->issue_tag_ids = $filters['issue_tag_ids'];
        $this->reference_ids = $filters['reference_ids'];
        $this->starts_after = $filters['starts_after'];
        $this->starts_before = $filters['starts_before'];
        $this->time_scope = $filters['time_scope'];
        $this->prayer_time = $filters['prayer_time'];
        $this->timing_mode = $filters['timing_mode'];
        $this->starts_time_from = $filters['starts_time_from'];
        $this->starts_time_until = $filters['starts_time_until'];
        $this->event_format = $filters['event_format'];
        $this->has_event_url = $filters['has_event_url'];
        $this->has_live_url = $filters['has_live_url'];
        $this->has_end_time = $filters['has_end_time'];
        $this->lat = $filters['lat'];
        $this->lng = $filters['lng'];
        $this->radius_km = $filters['radius_km'];
        $this->sort = $filters['sort'];
        $this->search_include_institutions = (bool) ($filters['search_include_institutions'] ?? true);
        $this->search_include_speakers = (bool) ($filters['search_include_speakers'] ?? true);
        $this->search_include_references = (bool) ($filters['search_include_references'] ?? true);
        $this->reference_author_search = $filters['reference_author_search'];
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

        if (! in_array($timeScope, ['upcoming', 'past', 'all'], true)) {
            $timeScope = (string) $defaults['time_scope'];
        }

        $sort = (string) ($normalized['sort'] ?? $defaults['sort']);

        if (! in_array($sort, ['time', 'relevance', 'distance'], true)) {
            $sort = (string) $defaults['sort'];
        }

        $timingMode = (string) ($normalized['timing_mode'] ?? '');

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
            'search_include_institutions' => (bool) ($normalized['search_include_institutions'] ?? true),
            'search_include_speakers' => (bool) ($normalized['search_include_speakers'] ?? true),
            'search_include_references' => (bool) ($normalized['search_include_references'] ?? true),
            'reference_author_search' => $this->normalizeStringArray($normalized['reference_author_search'] ?? []),
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
}
