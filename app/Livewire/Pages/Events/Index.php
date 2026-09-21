<?php

namespace App\Livewire\Pages\Events;

use AIArmada\Addressing\Data\AddressLocationData;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\State;
use AIArmada\Addressing\Support\AddressAreaStateBridge;
use AIArmada\Addressing\Support\AddressLocationScope;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Engagement\Contracts\EngagementManager;
use AIArmada\Engagement\Models\Bookmark;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Contracts\EventCategoryCatalog;
use App\Data\PublicScheduleLeaf;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\TimingMode;
use App\Forms\SharedFormSchema;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Language;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Services\EventSearchService;
use App\Services\PublicScheduleDiscoveryService;
use App\Support\Auth\IntendedRedirect;
use App\Support\Language\MalaysiaLanguageCatalog;
use App\Support\Location\LocationSlugResolver;
use App\Support\Location\PublicGeolocationPermission;
use App\Support\Location\VisitorCountryResolver;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Filament\Forms\Components\DatePicker;
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
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * @property-read Collection<int, AddressCountry> $countries
 * @property-read Collection<int, State> $states
 * @property-read array<string, string> $eventCategoryOptions
 */
#[Layout('layouts.app')]
#[Title('Upcoming Events')]
class Index extends Component implements HasForms
{
    use InteractsWithForms;
    use WithPagination;

    /**
     * Area roles with fixed filter selects. Form state always carries these
     * keys (null-filled) so nested bindings resolve; search criteria stay
     * sparse. The district/subdivision cascade slots resolve per country and
     * are merged in by {@see areaAssignmentRoles()}.
     */
    private const array FIXED_AREA_ASSIGNMENT_ROLES = [
        'administrative_division',
        'postal_locality',
    ];

    #[Url]
    public ?string $search = null;

    #[Url]
    public ?string $country_id = null;

    #[Url]
    public ?string $state_id = null;

    /** @var array<string, string> */
    #[Url]
    public array $area_assignments = [];

    /**
     * @var list<string>
     */
    #[Url]
    public array $language_codes = [];

    /**
     * @var list<string>|string|null
     */
    #[Url]
    public array|string|null $event_category_ids = [];

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

    /**
     * @var list<string>
     */
    #[Url]
    public array $person_ids = [];

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
     * Free-text person name match across speakers and every key-person role.
     *
     * Still honoured when present in a saved search or a shared URL; the
     * sidebar now filters speakers through the `person_ids` multi-select.
     */
    #[Url]
    public ?string $person_name_search = null;

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
    public array $discipline_tag_ids = [];

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

    /**
     * Single-select date shortcut (Semua | Hari ini | Esok | … | Julat tersuai).
     *
     * Form-only state (kept out of the URL on purpose): the canonical date range
     * travels as `starts_after`/`starts_before`, so saved searches and shared URLs
     * keep working. A named shortcut is resolved into `starts_after`/`starts_before`
     * by {@see normalizedUrlState()}; `custom` defers to the two date pickers.
     */
    public ?string $date_shortcut = null;

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

    /**
     * @var array<string, mixed>
     */
    public array $filterData = [];

    /**
     * @var array<string, Collection<int, string>>
     */
    private array $activeTaxonomyIdCache = [];

    /** @var LengthAwarePaginator<int, Event>|null */
    private ?LengthAwarePaginator $eventsForRequest = null;

    /** @var LengthAwarePaginator<int, PublicScheduleLeaf>|null */
    private ?LengthAwarePaginator $scheduleItemsForRequest = null;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public function mount(): void
    {
        $this->applyDefaultCountryScope();

        $normalized = $this->normalizedUrlState();

        $this->fillPublicPropertiesFromFilters($normalized);
        $this->filterData = $normalized;
        $this->filterData['area_assignments'] = $this->withAreaAssignmentDefaults($normalized['area_assignments']);
    }

    /**
     * Scope address filters to the visitor's country when the visitor has not
     * chosen one.
     *
     * The country carries no implicit meaning on its own — it only widens the
     * address cascade. The state, cascade-slot, division and locality fields
     * follow the resolved country's provider profile and are revealed by
     * their `visible()` guards. Countries without a provider simply leave
     * those levels empty.
     */
    private function applyDefaultCountryScope(): void
    {
        if (filled($this->country_id)) {
            return;
        }

        $this->country_id = app(VisitorCountryResolver::class)->resolve();
    }

    /**
     * The country the page falls back to when the visitor has not filtered.
     *
     * Used to keep the automatic scope out of the active-filter count so the
     * page does not look pre-filtered.
     */
    public function defaultCountryId(): ?string
    {
        return app(VisitorCountryResolver::class)->resolve();
    }

    public function showsGeolocationControls(): bool
    {
        return app(PublicGeolocationPermission::class)->isGranted();
    }

    public function sortForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('filterData')
            ->schema([
                Select::make('sort')
                    ->label(__('Susun'))
                    ->hiddenLabel()
                    ->options(fn (): array => [
                        'time' => __('Terbaru'),
                        'relevance' => __('Relevance'),
                        ...($this->lat !== null && $this->lng !== null ? ['distance' => __('Distance')] : []),
                    ])
                    ->extraAttributes(['data-signal-change-event' => 'filter.sort_changed'])
                    ->native(false)
                    ->live(),
            ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('filterData')
            ->columns([
                'default' => 1,
                'sm' => 2,
                'lg' => 3,
            ])
            ->schema([
                Section::make(__('Tarikh'))
                    ->extraAttributes(['class' => 'mi-advanced-filter-group'])
                    ->schema([
                        Select::make('date_shortcut')
                            ->label(__('Tarikh'))
                            ->options([
                                'all' => __('Semua'),
                                'today' => __('Hari ini'),
                                'tomorrow' => __('Esok'),
                                'this_week' => __('Minggu ini'),
                                'this_weekend' => __('Hujung minggu'),
                                'this_month' => __('Bulan ini'),
                                'next_week' => __('Minggu depan'),
                                'next_month' => __('Bulan depan'),
                                'custom' => __('Julat tersuai'),
                            ])
                            ->default('all')
                            ->selectablePlaceholder(false)
                            ->live()
                            ->afterStateUpdated(function (Set $set, $state): void {
                                // Any choice other than the custom range clears the
                                // hidden date pickers so "Semua" is a true no-constraint.
                                if ($state !== 'custom') {
                                    $set('starts_after', null);
                                    $set('starts_before', null);
                                }
                            })
                            ->extraAttributes(['data-signal-control' => 'date_shortcut']),

                        DatePicker::make('starts_after')
                            ->label(__('Dari'))
                            ->placeholder(__('Pilih tarikh mula'))
                            ->native(false)
                            ->extraAttributes(['data-signal-control' => 'starts_after'])
                            ->live()
                            ->visible(fn (Get $get): bool => ($get('date_shortcut') ?? 'all') === 'custom'),

                        DatePicker::make('starts_before')
                            ->label(__('Hingga'))
                            ->placeholder(__('Pilih tarikh akhir'))
                            ->native(false)
                            ->extraAttributes(['data-signal-control' => 'starts_before'])
                            ->live()
                            ->visible(fn (Get $get): bool => ($get('date_shortcut') ?? 'all') === 'custom'),
                    ]),

                Section::make(__('Format'))
                    ->extraAttributes(['class' => 'mi-advanced-filter-group'])
                    ->schema([
                        Select::make('event_format')
                            ->label(__('Format'))
                            ->placeholder(__('Semua format'))
                            ->searchable()
                            ->preload()
                            ->multiple()
                            ->options(collect(EventFormat::cases())
                                ->mapWithKeys(fn (EventFormat $format): array => [$format->value => $format->getLabel()])
                                ->all()
                            )
                            ->extraAttributes(['data-signal-control' => 'event_format'])
                            ->live(),
                    ]),

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
                        Select::make('country_id')
                            ->label(__('Country'))
                            ->placeholder(__('Any Country'))
                            ->searchable()
                            ->preload()
                            ->options(fn (): array => $this->countries
                                ->mapWithKeys(fn (AddressCountry $country): array => [(string) $country->getKey() => (string) $country->name])
                                ->all())
                            ->extraAttributes(['data-signal-control' => 'country_id'])
                            ->live(),

                        Select::make('state_id')
                            ->label($this->stateLabel())
                            ->placeholder(__('Pilih negeri'))
                            ->searchable()
                            ->preload()
                            ->disabled(fn (): bool => ! filled($this->country_id))
                            ->visible(fn (): bool => ! filled($this->country_id) || $this->states->isNotEmpty())
                            ->options(fn (): array => $this->states
                                ->mapWithKeys(fn (State $state): array => [(string) $state->getKey() => (string) $state->name])
                                ->all())
                            ->extraAttributes(['data-signal-control' => 'state_id'])
                            ->live(),

                        Select::make('area_assignments.administrative_division')
                            ->label(fn (): string => $this->divisionLabel())
                            ->placeholder(__('Any Division'))
                            ->searchable()
                            ->disabled(fn (): bool => ! filled($this->country_id) && ! filled($this->state_id))
                            ->visible(fn (): bool => SharedFormSchema::areaOptionsForRole($this->country_id, 'administrative_division', $this->state_id) !== [])
                            ->getSearchResultsUsing(fn (Get $get, string $search): array => $this->searchAreaOptions(
                                role: 'administrative_division',
                                countryId: $this->normalizeNullableString($get('country_id')),
                                stateId: $this->normalizeNullableString($get('state_id')),
                                search: $search,
                            ))
                            ->getOptionLabelUsing(fn (?string $value): ?string => $this->areaOptionLabel($value))
                            ->extraAttributes(['data-signal-control' => 'area_assignments.administrative_division'])
                            ->live(),

                        Select::make('area_assignments.postal_locality')
                            ->label(fn (): string => $this->localityLabel())
                            ->placeholder(__('Any Locality'))
                            ->searchable()
                            ->disabled(fn (): bool => ! filled($this->country_id) && ! filled($this->state_id))
                            ->visible(fn (): bool => SharedFormSchema::areaOptionsForRole($this->country_id, 'postal_locality', $this->state_id) !== [])
                            ->getSearchResultsUsing(fn (Get $get, string $search): array => $this->searchAreaOptions(
                                role: 'postal_locality',
                                countryId: $this->normalizeNullableString($get('country_id')),
                                stateId: $this->normalizeNullableString($get('state_id')),
                                search: $search,
                            ))
                            ->getOptionLabelUsing(fn (?string $value): ?string => $this->areaOptionLabel($value))
                            ->extraAttributes(['data-signal-control' => 'area_assignments.postal_locality'])
                            ->live(),

                        ...$this->areaSlotSelects(),

                        Select::make('institution_id')
                            ->label(__('Institution'))
                            ->placeholder(__('Any Institution'))
                            ->searchable()
                            ->getSearchResultsUsing(fn (Get $get, string $search): array => $this->searchInstitutionOptions(
                                countryId: $this->normalizeNullableString($get('country_id')),
                                stateId: $this->normalizeNullableString($get('state_id')),
                                areaAssignments: $this->normalizeAreaAssignments($get('area_assignments')),
                                search: $search,
                            ))
                            ->getOptionLabelUsing(fn (?string $value): ?string => $this->institutionOptionLabel($value))
                            ->helperText(__('Pilihan mengikut lokasi yang dipilih.'))
                            ->live(),
                    ]),

                Section::make(__('Bahasa'))
                    ->extraAttributes(['class' => 'mi-advanced-filter-group'])
                    ->schema([
                        Select::make('language_codes')
                            ->label(__('Bahasa'))
                            ->placeholder(__('Pilih bahasa...'))
                            ->helperText(__('Pilih satu atau lebih bahasa yang digunakan dalam majlis.'))
                            ->searchable()
                            ->preload()
                            ->multiple()
                            ->options(fn (): array => $this->languageOptions())
                            ->live(),
                    ]),

                Section::make(__('Penceramah'))
                    ->extraAttributes(['class' => 'mi-advanced-filter-group'])
                    ->schema([
                        Select::make('person_ids')
                            ->label(__('Penceramah'))
                            ->placeholder(__('Cari atau pilih penceramah...'))
                            ->helperText(__('Pilih satu atau lebih penceramah.'))
                            ->searchable()
                            ->multiple()
                            ->getSearchResultsUsing(fn (string $search): array => $this->searchPersonOptions($search))
                            ->getOptionLabelsUsing(fn (array $values): array => $this->personOptionLabels($values))
                            ->extraAttributes(['data-signal-control' => 'person_ids'])
                            ->live(),
                    ]),

                Section::make(__('Topik & rujukan'))
                    ->extraAttributes(['class' => 'mi-advanced-filter-group'])
                    ->schema([
                        Select::make('event_category_ids')
                            ->label(__('Jenis majlis'))
                            ->placeholder(__('Semua jenis majlis'))
                            ->searchable()
                            ->preload()
                            ->multiple()
                            ->options(fn (): array => $this->eventCategoryOptions)
                            ->extraAttributes(['data-signal-control' => 'event_category_ids'])
                            ->live(),

                        Select::make('domain_tag_ids')
                            ->label(__('Topik / bidang'))
                            ->placeholder(__('Pilih topik…'))
                            ->searchable()
                            ->multiple()
                            ->getSearchResultsUsing(fn (string $search): array => $this->searchTermOptions('domain', $search))
                            ->getOptionLabelsUsing(fn (array $values): array => $this->termOptionLabels('domain', $values))
                            ->live(),

                        Select::make('discipline_tag_ids')
                            ->label(__('Topik lebih khusus'))
                            ->placeholder(__('Pilih topik khusus…'))
                            ->searchable()
                            ->multiple()
                            ->getSearchResultsUsing(fn (string $search): array => $this->searchTermOptions('discipline', $search))
                            ->getOptionLabelsUsing(fn (array $values): array => $this->termOptionLabels('discipline', $values))
                            ->live(),

                        Select::make('source_tag_ids')
                            ->label(__('Sumber Rujukan Utama'))
                            ->placeholder(__('Pilih sumber...'))
                            ->searchable()
                            ->multiple()
                            ->getSearchResultsUsing(fn (string $search): array => $this->searchTermOptions('source', $search))
                            ->getOptionLabelsUsing(fn (array $values): array => $this->termOptionLabels('source', $values))
                            ->live(),

                        Select::make('issue_tag_ids')
                            ->label(__('Tema / Isu'))
                            ->placeholder(__('Pilih atau taip untuk tambah tema...'))
                            ->searchable()
                            ->multiple()
                            ->getSearchResultsUsing(fn (string $search): array => $this->searchTermOptions('issue', $search))
                            ->getOptionLabelsUsing(fn (array $values): array => $this->termOptionLabels('issue', $values))
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

                Section::make(__('Lokasi berdekatan'))
                    ->extraAttributes(['class' => 'mi-advanced-filter-group'])
                    ->visible(fn (): bool => filled($this->lat))
                    ->schema([
                        TextInput::make('radius_km')
                            ->label(__('Radius'))
                            ->helperText(__('Digunakan apabila mencari majlis berdekatan lokasi anda.'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(1000)
                            ->step(1)
                            ->suffix('km')
                            ->extraAttributes(['data-signal-control' => 'radius_km'])
                            ->extraFieldWrapperAttributes(fn (): array => [
                                'data-testid' => 'nearby-radius-inline',
                                'x-cloak' => true,
                                'x-bind:hidden' => '! geolocationPermitted',
                                ...(! $this->showsGeolocationControls() ? ['hidden' => true] : []),
                            ])
                            ->visible(fn (): bool => filled($this->lat))
                            ->live(),
                    ]),
            ]);
    }

    public function updatedFilterData(mixed $value = null, ?string $key = null): void
    {
        [$slot0, $slot1] = $this->slotRoles();

        if ($key === 'country_id') {
            $this->filterData['state_id'] = null;
            $this->filterData['area_assignments'] = $this->withAreaAssignmentDefaults([]);
        } elseif ($key === 'state_id') {
            $this->filterData['area_assignments'] = $this->withAreaAssignmentDefaults([]);
        } elseif ($key === 'area_assignments.administrative_division') {
            // A slot colliding with the fixed division field defers to it
            // (see areaSlotSelects) and is never cleared here.
            if ($slot0 !== null && $slot0 !== 'administrative_division') {
                $this->filterData['area_assignments'][$slot0] = null;
            }

            if ($slot1 !== null && $slot1 !== 'administrative_division') {
                $this->filterData['area_assignments'][$slot1] = null;
            }
        } elseif ($slot0 !== null && $key === "area_assignments.{$slot0}") {
            if ($slot1 !== null) {
                $this->filterData['area_assignments'][$slot1] = null;
            }
        }

        $normalized = $this->normalizedFilterData($this->filterData);

        // A location change re-scopes the institution options. The selected
        // institution survives when it is still inside the new scope and is
        // reset only when it falls outside of it.
        if ($this->isLocationFilterKey($key) && ! $this->institutionWithinScope(
            $normalized['institution_id'] ?? null,
            $normalized['country_id'],
            $normalized['state_id'] ?? null,
            $normalized['area_assignments'],
        )) {
            $normalized['institution_id'] = null;
            $this->filterData['institution_id'] = null;
        }

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

        $this->resetPage();
    }

    public function clearAllFilters(): void
    {
        $defaults = $this->defaultFilterData();

        $this->fillPublicPropertiesFromFilters($defaults);
        $this->filterData = $defaults;

        // Restore the visitor's country so "clear" returns to how the page
        // loaded rather than silently widening the search to every country.
        $this->applyDefaultCountryScope();

        $normalized = $this->normalizedUrlState();

        $this->fillPublicPropertiesFromFilters($normalized);
        $this->filterData = $normalized;
        $this->filterData['area_assignments'] = $this->withAreaAssignmentDefaults($normalized['area_assignments']);

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

        $isSaved = Bookmark::forBookmarker($user)
            ->forBookmarkable($event)
            ->active()
            ->exists();

        if ($isSaved) {
            app(EngagementManager::class)->removeBookmark($user, $event);

            return;
        }

        app(EngagementManager::class)->bookmark($user, $event);
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

        foreach ($this->scheduleItems()->items() as $scheduleLeaf) {
            if ($scheduleLeaf instanceof PublicScheduleLeaf) {
                $eventIds[] = (string) $scheduleLeaf->event->getKey();
            }
        }

        if ($eventIds === []) {
            return [];
        }

        return Bookmark::forBookmarker($user)
            ->whereIn('bookmarkable_id', $eventIds)
            ->active()
            ->pluck('bookmarkable_id')
            ->map(fn (mixed $eventId): string => (string) $eventId)
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, AddressCountry>
     */
    #[Computed]
    public function countries(): Collection
    {
        return AddressCountry::query()
            ->orderBy('name')
            ->get(['id', 'name', 'iso2']);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function eventCategoryOptions(): array
    {
        return app(EventCategoryCatalog::class)->options();
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

        if (app(LocationSlugResolver::class)->stateMaps($this->country_id)['options'] === []) {
            return collect();
        }

        return State::query()
            ->where('country_id', $this->country_id)
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string, string>  $areaAssignments
     * @return array<string, string>
     */
    private function searchInstitutionOptions(
        ?string $countryId,
        ?string $stateId,
        array $areaAssignments,
        string $search = '',
    ): array {
        $query = Institution::query()
            ->whereIn('status', ['verified', 'pending']);

        $this->applyAddressLocationFilters($query, $countryId, $areaAssignments, $stateId);
        $query->searchNameOrNickname($search);

        return $this->institutionOptionsFromQuery($query->orderBy('name'), 50);
    }

    /**
     * Area roles with filter selects: the fixed division/locality roles plus
     * the country's resolved cascade-slot roles.
     *
     * @return list<string>
     */
    private function areaAssignmentRoles(): array
    {
        return array_values(array_unique(array_merge(
            self::FIXED_AREA_ASSIGNMENT_ROLES,
            array_filter($this->slotRoles()),
        )));
    }

    /**
     * Cascade-slot roles for the selected country. The slots show the two
     * deepest area levels of the country's provider profile; a null slot
     * renders no field.
     *
     * @return array{?string, ?string}
     */
    private function slotRoles(): array
    {
        $countryId = $this->normalizeNullableString($this->country_id);
        $resolver = app(LocationSlugResolver::class);

        return [
            $resolver->districtRoleForCountry($countryId),
            $resolver->subdivisionRoleForCountry($countryId),
        ];
    }

    public function stateLabel(): string
    {
        return SharedFormSchema::locationLevelLabel($this->normalizeNullableString($this->country_id), 'state_id', __('State / Province'));
    }

    public function districtLabel(): string
    {
        $countryId = $this->normalizeNullableString($this->country_id);
        $role = app(LocationSlugResolver::class)->districtRoleForCountry($countryId);

        return $role === null
            ? __('District')
            : SharedFormSchema::locationLevelLabel($countryId, $role, __('District'), $this->normalizeNullableString($this->state_id));
    }

    public function subdistrictLabel(): string
    {
        $countryId = $this->normalizeNullableString($this->country_id);
        $role = app(LocationSlugResolver::class)->subdivisionRoleForCountry($countryId);

        return $role === null
            ? __('Subdivision')
            : SharedFormSchema::locationLevelLabel($countryId, $role, __('Subdivision'), $this->normalizeNullableString($this->state_id), $this->normalizeAreaAssignments($this->area_assignments));
    }

    public function divisionLabel(): string
    {
        return SharedFormSchema::locationLevelLabel(
            $this->normalizeNullableString($this->country_id),
            'administrative_division',
            __('Division / Bahagian'),
            $this->normalizeNullableString($this->state_id),
            $this->normalizeAreaAssignments($this->area_assignments),
        );
    }

    public function localityLabel(): string
    {
        return SharedFormSchema::locationLevelLabel(
            $this->normalizeNullableString($this->country_id),
            'postal_locality',
            __('Locality'),
            $this->normalizeNullableString($this->state_id),
            $this->normalizeAreaAssignments($this->area_assignments),
        );
    }

    public function stateChipLabel(): string
    {
        $areaId = AddressAreaStateBridge::areaIdForState($this->normalizeNullableString($this->state_id));

        if ($areaId !== null && AddressArea::query()->whereKey($areaId)->value('type') === 'wilayah_persekutuan') {
            return __('WP');
        }

        return __('Negeri');
    }

    /**
     * Cascade-slot selects for the selected country. Slots whose role
     * collides with a fixed field (division) defer to that field.
     *
     * @return list<Select>
     */
    private function areaSlotSelects(): array
    {
        [$slot0, $slot1] = $this->slotRoles();

        $selects = [];

        if ($slot0 !== null && ! in_array($slot0, self::FIXED_AREA_ASSIGNMENT_ROLES, true)) {
            $selects[] = Select::make("area_assignments.{$slot0}")
                ->label(fn (): string => $this->districtLabel())
                ->placeholder(fn (): string => __('All :level', ['level' => $this->districtLabel()]))
                ->searchable()
                ->disabled(fn (): bool => ! filled($this->country_id) && ! filled($this->state_id))
                ->visible(fn (): bool => SharedFormSchema::areaOptionsForRole($this->country_id, $slot0, $this->state_id) !== [])
                ->getSearchResultsUsing(fn (Get $get, string $search): array => $this->searchAreaOptions(
                    role: $slot0,
                    countryId: $this->normalizeNullableString($get('country_id')),
                    stateId: $this->normalizeNullableString($get('state_id')),
                    search: $search,
                ))
                ->getOptionLabelUsing(fn (?string $value): ?string => $this->areaOptionLabel($value))
                ->extraAttributes(['data-signal-control' => "area_assignments.{$slot0}"])
                ->live();
        }

        if ($slot1 !== null && ! in_array($slot1, self::FIXED_AREA_ASSIGNMENT_ROLES, true)) {
            $selects[] = Select::make("area_assignments.{$slot1}")
                ->label(fn (): string => $this->subdistrictLabel())
                ->placeholder(fn (): string => __('All :level', ['level' => $this->subdistrictLabel()]))
                ->searchable()
                ->disabled(fn (): bool => ! filled($this->country_id) && ! filled($this->state_id))
                ->visible(fn (): bool => $this->subdivisionSlotOptions() !== [])
                ->getSearchResultsUsing(fn (Get $get, string $search): array => $this->searchAreaOptions(
                    role: $slot1,
                    countryId: $this->normalizeNullableString($get('country_id')),
                    stateId: $this->normalizeNullableString($get('state_id')),
                    districtId: $this->normalizeAreaAssignments($get('area_assignments'))[$slot0 ?? ''] ?? null,
                    search: $search,
                ))
                ->getOptionLabelUsing(fn (?string $value): ?string => $this->areaOptionLabel($value))
                ->extraAttributes(['data-signal-control' => "area_assignments.{$slot1}"])
                ->live();
        }

        return $selects;
    }

    /**
     * @return array<int|string, string>
     */
    private function subdivisionSlotOptions(): array
    {
        [, $slot1] = $this->slotRoles();

        if ($slot1 === null) {
            return [];
        }

        return $this->searchAreaOptions(
            role: $slot1,
            countryId: $this->normalizeNullableString($this->country_id),
            stateId: $this->normalizeNullableString($this->state_id),
            districtId: $this->normalizeAreaAssignments($this->area_assignments)[$this->slotRoles()[0] ?? ''] ?? null,
        );
    }

    /**
     * @return array<string, string>
     */
    private function searchAreaOptions(
        string $role,
        ?string $countryId,
        ?string $stateId,
        string $search = '',
        ?string $districtId = null,
    ): array {
        if (! filled($stateId) && ! filled($countryId)) {
            return [];
        }

        $slot1 = app(LocationSlugResolver::class)->subdivisionRoleForCountry($countryId);

        // The second slot searches under the selected first-slot area,
        // falling back to the state (or the whole country) so deep areas
        // stay directly searchable without selecting every parent first.
        $parentId = $slot1 !== null && $role === $slot1 ? ($districtId ?? $stateId) : $stateId;

        $options = SharedFormSchema::areaOptionsForRole($countryId, $role, $parentId);

        return $this->filterAreaOptions($options, $search);
    }

    public function areaOptionLabel(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $id = SharedFormSchema::normalizeLocationId($value);

        if ($id === null) {
            return null;
        }

        return AddressArea::query()->whereKey($id)->value('name');
    }

    /**
     * @param  array<int|string, string>  $options
     * @return array<string, string>
     */
    private function filterAreaOptions(array $options, string $search): array
    {
        $normalizedSearch = mb_strtolower(trim($search));

        return collect($options)
            ->when($normalizedSearch !== '', fn (Collection $matches): Collection => $matches
                ->filter(fn (string $label): bool => str_contains(mb_strtolower($label), $normalizedSearch)))
            ->take(50)
            ->mapWithKeys(fn (string $label, int|string $id): array => [(string) $id => $label])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function searchPersonOptions(string $search = ''): array
    {
        /** @var Collection<int, Person> $persons */
        $persons = Person::query()
            ->whereIn('status', ['verified', 'pending'])
            ->tap(fn (Builder $query): Builder => $this->applySearchConstraint($query, 'name', $search))
            ->orderBy('name')
            ->with('titleAssignments.title.category')
            ->limit(50)
            ->get(['id', 'name', 'middle_name', 'family_name']);

        return $persons
            ->mapWithKeys(fn (Person $person): array => [(string) $person->id => $person->formatted_name])
            ->all();
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    public function personOptionLabels(array $values): array
    {
        if ($values === []) {
            return [];
        }

        /** @var Collection<int, Person> $persons */
        $persons = Person::query()
            ->whereIn('status', ['verified', 'pending'])
            ->whereIn('id', $values)
            ->with('titleAssignments.title.category')
            ->get(['id', 'name', 'middle_name', 'family_name']);

        return $persons
            ->mapWithKeys(fn (Person $person): array => [(string) $person->id => $person->formatted_name])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function searchTermOptions(string $taxonomyCode, string $search): array
    {
        return $this->pluckOptions(
            EventTerm::query()
                ->whereIn('event_taxonomy_id', $this->activeTaxonomyIds($taxonomyCode))
                ->where('is_active', true)
                ->tap(fn (Builder $query): Builder => $this->applySearchConstraint($query, 'name', $search))
                ->orderBy('sort_order'),
            'name',
            50,
        );
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    public function termOptionLabels(string $taxonomyCode, array $values): array
    {
        if ($values === []) {
            return [];
        }

        return $this->pluckOptions(
            EventTerm::query()
                ->whereIn('event_taxonomy_id', $this->activeTaxonomyIds($taxonomyCode))
                ->where('is_active', true)
                ->whereIn('id', $values)
                ->orderBy('sort_order'),
            'name',
            count($values),
        );
    }

    /**
     * @return Collection<int, string>
     */
    private function activeTaxonomyIds(string $code): Collection
    {
        return $this->activeTaxonomyIdCache[$code] ??= EventTaxonomy::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->pluck('id');
    }

    /**
     * @return array<string, string>
     */
    private function searchReferenceOptions(string $search): array
    {
        $query = Reference::query()
            ->active()
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
                ->active()
                ->whereIn('id', $values)
                ->orderBy('title'),
            count($values),
        );
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
            ->get(['id', 'title', 'parent_id', 'metadata']);

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

        return $query->where(function (Builder $referenceQuery) use ($normalizedSearch): void {
            $referenceQuery
                ->whereLike('title', "%{$normalizedSearch}%")
                ->orWherePartTextLike("%{$normalizedSearch}%");
        });
    }

    public function institutionOptionLabel(?string $value): ?string
    {
        $id = $value === null ? null : SharedFormSchema::normalizeLocationId($value);

        if ($id === null) {
            return null;
        }

        return Institution::query()
            ->whereIn('status', ['verified', 'pending'])
            ->whereKey($id)
            ->with('names')->first(['id', 'name'])
            ?->display_name;
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
            ->with('names')
            ->limit($limit)
            ->get(['id', 'name']);

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

        return $query->whereLike($column, "%{$normalizedSearch}%");
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, string>  $areaAssignments
     */
    private function applyAddressLocationFilters(
        Builder $query,
        ?string $countryId,
        array $areaAssignments,
        ?string $stateId = null,
    ): void {
        app(AddressLocationScope::class)->apply($query, new AddressLocationData(
            countryId: filled($countryId) ? $countryId : null,
            stateId: filled($stateId) ? $stateId : null,
            areaAssignments: $areaAssignments,
        ));
    }

    private function isLocationFilterKey(?string $key): bool
    {
        if ($key === null) {
            return false;
        }

        return in_array($key, ['country_id', 'state_id'], true)
            || str_starts_with($key, 'area_assignments.');
    }

    /**
     * Whether the selected institution falls inside the selected location
     * scope, using the same constraints as the institution options.
     *
     * @param  array<string, string>  $areaAssignments
     */
    private function institutionWithinScope(
        mixed $institutionId,
        ?string $countryId,
        ?string $stateId,
        array $areaAssignments,
    ): bool {
        $institutionId = SharedFormSchema::normalizeLocationId($institutionId);

        if ($institutionId === null) {
            return true;
        }

        if ($countryId === null && $stateId === null && $areaAssignments === []) {
            return true;
        }

        $query = Institution::query()
            ->whereKey($institutionId)
            ->whereIn('status', ['verified', 'pending']);

        $this->applyAddressLocationFilters($query, $countryId, $areaAssignments, $stateId);

        return $query->exists();
    }

    /**
     * @return LengthAwarePaginator<int, Event>
     */
    #[Computed]
    public function events(): LengthAwarePaginator
    {
        if ($this->eventsForRequest instanceof LengthAwarePaginator) {
            return $this->eventsForRequest;
        }

        $filters = $this->normalizedUrlState();
        $searchFilters = $this->buildSearchFilters($filters);
        $searchService = app(EventSearchService::class);

        if ($filters['lat'] !== null && $filters['lng'] !== null) {
            return $this->eventsForRequest = $searchService->searchNearby(
                lat: (float) $filters['lat'],
                lng: (float) $filters['lng'],
                radiusKm: $filters['radius_km'],
                filters: $searchFilters,
                perPage: 12,
            );
        }

        return $this->eventsForRequest = $searchService->search(
            query: $filters['search'],
            filters: $searchFilters,
            perPage: 12,
            sort: $filters['sort'],
        );
    }

    /**
     * @return LengthAwarePaginator<int, PublicScheduleLeaf>
     */
    #[Computed]
    public function scheduleItems(): LengthAwarePaginator
    {
        if ($this->scheduleItemsForRequest instanceof LengthAwarePaginator) {
            return $this->scheduleItemsForRequest;
        }

        $filters = $this->normalizedUrlState();
        $searchFilters = $this->buildSearchFilters($filters);

        /** @var PublicScheduleDiscoveryService $discovery */
        $discovery = app(PublicScheduleDiscoveryService::class);

        return $this->scheduleItemsForRequest = $discovery->search(
            query: $filters['search'],
            filters: $searchFilters,
            perPage: 12,
            sort: $filters['sort'],
            latitude: $filters['lat'] !== null ? (float) $filters['lat'] : null,
            longitude: $filters['lng'] !== null ? (float) $filters['lng'] : null,
            radiusKm: (float) $filters['radius_km'],
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function buildSearchFilters(array $filters): array
    {
        $searchFilters = [
            'country_id' => $filters['country_id'],
            'state_id' => $filters['state_id'] ?? null,
            'area_assignments' => $filters['area_assignments'] ?? [],
            'language_codes' => $filters['language_codes'],
            'event_category_ids' => $filters['event_category_ids'],
            'gender' => $filters['gender'],
            'age_group' => $filters['age_group'],
            'children_allowed' => $filters['children_allowed'],
            'is_muslim_only' => $filters['is_muslim_only'],
            'institution_id' => $filters['institution_id'],
            'person_ids' => $filters['person_ids'],
            'key_person_roles' => $filters['key_person_roles'],
            'person_in_charge_ids' => $filters['person_in_charge_ids'],
            'person_in_charge_search' => $filters['person_in_charge_search'],
            'person_name_search' => $filters['person_name_search'],
            'moderator_ids' => $filters['moderator_ids'],
            'imam_ids' => $filters['imam_ids'],
            'khatib_ids' => $filters['khatib_ids'],
            'bilal_ids' => $filters['bilal_ids'],
            'discipline_tag_ids' => $filters['discipline_tag_ids'],
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

        // The /majlis keyword search is event-focused: it matches event titles
        // only. Institution, person, and reference discovery live on their
        // dedicated pages (/institusi, /penceramah, /rujukan).
        $searchFilters['search_include_institutions'] = false;
        $searchFilters['search_include_persons'] = false;
        $searchFilters['search_include_references'] = false;

        return $searchFilters;
    }

    /**
     * @return array<string, string>
     */
    public function languageOptions(): array
    {
        return cache()->remember('event_filter_languages_v4', 3600, function (): array {
            $preferredOrder = MalaysiaLanguageCatalog::codes();
            $preferredLabels = MalaysiaLanguageCatalog::labels();

            return Language::query()
                ->whereIn('code', $preferredOrder)
                ->get(['code', 'name'])
                ->sortBy(fn (Language $language): int|false => array_search((string) $language->code, $preferredOrder, true))
                ->mapWithKeys(function (Language $language) use ($preferredLabels): array {
                    $code = (string) $language->code;
                    $label = $preferredLabels[$code] ?? (string) ($language->name ?? strtoupper($code));

                    return [$code => $label.' ('.($code === 'ms' ? 'BM' : strtoupper($code)).')'];
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
            'country_id' => null,
            'state_id' => null,
            'area_assignments' => $this->withAreaAssignmentDefaults([]),
            'language_codes' => [],
            'event_category_ids' => [],
            'gender' => null,
            'age_group' => [],
            'children_allowed' => null,
            'is_muslim_only' => null,
            'institution_id' => null,
            'person_ids' => [],
            'key_person_roles' => [],
            'person_in_charge_ids' => [],
            'person_in_charge_search' => null,
            'person_name_search' => null,
            'moderator_ids' => [],
            'imam_ids' => [],
            'khatib_ids' => [],
            'bilal_ids' => [],
            'discipline_tag_ids' => [],
            'domain_tag_ids' => [],
            'source_tag_ids' => [],
            'issue_tag_ids' => [],
            'reference_ids' => [],
            'starts_after' => null,
            'starts_before' => null,
            'date_shortcut' => 'all',
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

        $dateShortcut = $this->effectiveDateShortcut($this->date_shortcut, $this->starts_after, $this->starts_before);
        $dateRange = $this->dateRangeForShortcut($dateShortcut, $this->starts_after, $this->starts_before);

        return [
            'search' => filled($this->search) ? trim($this->search) : null,
            'country_id' => filled($this->country_id) ? $this->country_id : null,
            'state_id' => filled($this->state_id) ? $this->state_id : null,
            'area_assignments' => $this->normalizeAreaAssignments($this->area_assignments),
            'language_codes' => $languageCodes,
            'event_category_ids' => $this->normalizeStringArray($this->event_category_ids),
            'gender' => filled($this->gender) ? $this->gender : null,
            'age_group' => $this->normalizeStringArray($this->age_group),
            'children_allowed' => $this->normalizeNullableBoolean($this->children_allowed),
            'is_muslim_only' => $this->normalizeNullableBoolean($this->is_muslim_only),
            'institution_id' => filled($this->institution_id) ? $this->institution_id : null,
            'person_ids' => $this->normalizeStringArray($this->person_ids),
            'key_person_roles' => $this->normalizeStringArray($this->key_person_roles),
            'person_in_charge_ids' => $this->normalizeStringArray($this->person_in_charge_ids),
            'person_in_charge_search' => filled($this->person_in_charge_search) ? trim($this->person_in_charge_search) : null,
            'person_name_search' => filled($this->person_name_search) ? trim($this->person_name_search) : null,
            'moderator_ids' => $this->normalizeStringArray($this->moderator_ids),
            'imam_ids' => $this->normalizeStringArray($this->imam_ids),
            'khatib_ids' => $this->normalizeStringArray($this->khatib_ids),
            'bilal_ids' => $this->normalizeStringArray($this->bilal_ids),
            'discipline_tag_ids' => $this->normalizeStringArray($this->discipline_tag_ids),
            'domain_tag_ids' => $this->normalizeStringArray($this->domain_tag_ids),
            'source_tag_ids' => $this->normalizeStringArray($this->source_tag_ids),
            'issue_tag_ids' => $this->normalizeStringArray($this->issue_tag_ids),
            'reference_ids' => $this->normalizeStringArray($this->reference_ids),
            'date_shortcut' => $dateShortcut,
            'starts_after' => $dateRange['starts_after'],
            'starts_before' => $dateRange['starts_before'],
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
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function fillPublicPropertiesFromFilters(array $filters): void
    {
        $this->search = $filters['search'];
        $this->country_id = $filters['country_id'];
        $this->state_id = $filters['state_id'] ?? null;
        $this->area_assignments = $filters['area_assignments'];
        $this->language_codes = $filters['language_codes'];
        $this->event_category_ids = $filters['event_category_ids'];
        $this->gender = $filters['gender'];
        $this->age_group = $filters['age_group'];
        $this->children_allowed = $filters['children_allowed'];
        $this->is_muslim_only = $filters['is_muslim_only'];
        $this->institution_id = $filters['institution_id'];
        $this->person_ids = $filters['person_ids'];
        $this->key_person_roles = $filters['key_person_roles'];
        $this->person_in_charge_ids = $filters['person_in_charge_ids'];
        $this->person_in_charge_search = $filters['person_in_charge_search'];
        $this->person_name_search = $filters['person_name_search'];
        $this->moderator_ids = $filters['moderator_ids'];
        $this->imam_ids = $filters['imam_ids'];
        $this->khatib_ids = $filters['khatib_ids'];
        $this->bilal_ids = $filters['bilal_ids'];
        $this->discipline_tag_ids = $filters['discipline_tag_ids'];
        $this->domain_tag_ids = $filters['domain_tag_ids'];
        $this->source_tag_ids = $filters['source_tag_ids'];
        $this->issue_tag_ids = $filters['issue_tag_ids'];
        $this->reference_ids = $filters['reference_ids'];
        $this->starts_after = $filters['starts_after'];
        $this->starts_before = $filters['starts_before'];
        $this->date_shortcut = $filters['date_shortcut'] ?? 'all';
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

        $dateShortcut = $this->effectiveDateShortcut($normalized['date_shortcut'] ?? null, $normalized['starts_after'] ?? null, $normalized['starts_before'] ?? null);
        $dateRange = $this->dateRangeForShortcut($dateShortcut, $normalized['starts_after'] ?? null, $normalized['starts_before'] ?? null);

        return [
            'search' => filled($normalized['search']) ? trim((string) $normalized['search']) : null,
            'country_id' => filled($normalized['country_id']) ? (string) $normalized['country_id'] : null,
            'state_id' => filled($normalized['state_id'] ?? null) ? (string) $normalized['state_id'] : null,
            'area_assignments' => $this->normalizeAreaAssignments($normalized['area_assignments'] ?? []),
            'language_codes' => $languageCodes,
            'event_category_ids' => $this->normalizeStringArray($normalized['event_category_ids'] ?? []),
            'gender' => filled($normalized['gender']) ? (string) $normalized['gender'] : null,
            'age_group' => $this->normalizeStringArray($normalized['age_group'] ?? []),
            'children_allowed' => $this->normalizeNullableBoolean($normalized['children_allowed'] ?? null),
            'is_muslim_only' => $this->normalizeNullableBoolean($normalized['is_muslim_only'] ?? null),
            'institution_id' => filled($normalized['institution_id']) ? (string) $normalized['institution_id'] : null,
            'person_ids' => $this->normalizeStringArray($normalized['person_ids'] ?? []),
            'key_person_roles' => $this->normalizeStringArray($normalized['key_person_roles'] ?? []),
            'person_in_charge_ids' => $this->normalizeStringArray($normalized['person_in_charge_ids'] ?? []),
            'person_in_charge_search' => filled($normalized['person_in_charge_search'] ?? null) ? trim((string) $normalized['person_in_charge_search']) : null,
            'person_name_search' => filled($normalized['person_name_search'] ?? null) ? trim((string) $normalized['person_name_search']) : null,
            'moderator_ids' => $this->normalizeStringArray($normalized['moderator_ids'] ?? []),
            'imam_ids' => $this->normalizeStringArray($normalized['imam_ids'] ?? []),
            'khatib_ids' => $this->normalizeStringArray($normalized['khatib_ids'] ?? []),
            'bilal_ids' => $this->normalizeStringArray($normalized['bilal_ids'] ?? []),
            'discipline_tag_ids' => $this->normalizeStringArray($normalized['discipline_tag_ids'] ?? []),
            'domain_tag_ids' => $this->normalizeStringArray($normalized['domain_tag_ids'] ?? []),
            'source_tag_ids' => $this->normalizeStringArray($normalized['source_tag_ids'] ?? []),
            'issue_tag_ids' => $this->normalizeStringArray($normalized['issue_tag_ids'] ?? []),
            'reference_ids' => $this->normalizeStringArray($normalized['reference_ids'] ?? []),
            'date_shortcut' => $dateShortcut,
            'starts_after' => $dateRange['starts_after'],
            'starts_before' => $dateRange['starts_before'],
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
     * Resolve which date shortcut is active, inferring `custom` when an explicit
     * range (starts_after / starts_before) is present without a chosen shortcut —
     * e.g. a shared URL or a saved search that stored the raw dates.
     */
    private function effectiveDateShortcut(mixed $shortcut, mixed $rawAfter, mixed $rawBefore): string
    {
        $allowed = ['all', 'today', 'tomorrow', 'this_week', 'this_weekend', 'this_month', 'next_week', 'next_month', 'custom'];
        $value = is_string($shortcut) ? $shortcut : '';

        if (! in_array($value, $allowed, true)) {
            $value = '';
        }

        if ($value === '' || $value === 'all') {
            $value = (filled($rawAfter) || filled($rawBefore)) ? 'custom' : 'all';
        }

        return $value;
    }

    /**
     * Map a date shortcut to the canonical starts_after / starts_before pair.
     *
     * `custom` defers to the two date pickers; named shortcuts compute an
     * inclusive day range (the search layer treats starts_before as end-of-day).
     *
     * @return array{starts_after: ?string, starts_before: ?string}
     */
    private function dateRangeForShortcut(string $shortcut, ?string $rawAfter, ?string $rawBefore): array
    {
        if ($shortcut === 'custom') {
            return [
                'starts_after' => filled($rawAfter) ? (string) $rawAfter : null,
                'starts_before' => filled($rawBefore) ? (string) $rawBefore : null,
            ];
        }

        if ($shortcut === 'all' || $shortcut === '') {
            return ['starts_after' => null, 'starts_before' => null];
        }

        $today = UserDateTimeFormatter::userNow()->startOfDay();

        [$after, $before] = match ($shortcut) {
            'today' => [$today, $today],
            'tomorrow' => [$today->copy()->addDay(), $today->copy()->addDay()],
            'this_week' => [$today->copy()->startOfWeek(), $today->copy()->endOfWeek()],
            'this_weekend' => $this->weekendDateRange($today),
            'this_month' => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
            'next_week' => [$today->copy()->startOfWeek()->addWeek(), $today->copy()->endOfWeek()->addWeek()],
            'next_month' => [$today->copy()->startOfMonth()->addMonth(), $today->copy()->endOfMonth()->addMonth()],
            default => [null, null],
        };

        if ($after === null || $before === null) {
            return ['starts_after' => null, 'starts_before' => null];
        }

        return [
            'starts_after' => $after->toDateString(),
            'starts_before' => $before->toDateString(),
        ];
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function weekendDateRange(CarbonInterface $today): array
    {
        // On Sunday the weekend began yesterday: anchor on Saturday so the
        // range stays Sat + Sun instead of drifting into Monday. Past
        // Saturday events stay excluded because the discovery layer drops
        // leaves that already ended for the upcoming time scope.
        $weekendStart = $today->isSunday()
            ? $today->copy()->subDay()
            : ($today->isSaturday() ? $today->copy() : $today->copy()->next(CarbonInterface::SATURDAY));

        // Inclusive Sat + Sun; the search layer extends starts_before to end-of-day.
        return [$weekendStart, $weekendStart->copy()->addDay()];
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

    /**
     * @return array<string, string>
     */
    private function normalizeAreaAssignments(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $assignments = [];

        foreach ($value as $role => $areaId) {
            if (! is_string($role) || ! is_scalar($areaId)) {
                continue;
            }

            $role = trim($role);
            $areaId = SharedFormSchema::normalizeLocationId($areaId);

            if ($role !== '' && $areaId !== null) {
                $assignments[$role] = $areaId;
            }
        }

        return $assignments;
    }

    /**
     * @param  array<string, ?string>  $assignments
     * @return array<string, ?string>
     */
    private function withAreaAssignmentDefaults(array $assignments): array
    {
        return $assignments + array_fill_keys($this->areaAssignmentRoles(), null);
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
            return UserDateTimeFormatter::userNow()->setTimeFromTimeString($normalized)->format('H:i');
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
