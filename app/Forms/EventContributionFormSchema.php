<?php

namespace App\Forms;

use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Actions\References\GenerateReferenceSlugAction;
use App\Contracts\EventCategoryCatalog;
use App\Contracts\EventCategoryPolicyResolver;
use App\Contracts\SpaceEligibilityResolver;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventTaxonomyCode;
use App\Enums\EventVisibility;
use App\Enums\ReferenceType;
use App\Forms\Components\Select;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Series;
use App\Models\Venue;
use App\Support\Cache\SelectionCatalogCache;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EventContributionFormSchema
{
    private static ?string $cacheScope = null;

    /** @var array<string, string>|null */
    private static ?array $languageOptionsCache = null;

    /** @var array<string, string>|null */
    private static ?array $personOptionsCache = null;

    /** @var array<string, string>|null */
    private static ?array $institutionOptionsCache = null;

    /** @var array<string, array<string, string>> */
    private static array $tagOptionsCache = [];

    private static function ensureCacheScope(): void
    {
        $scope = spl_object_hash(app());

        if (self::$cacheScope === $scope) {
            return;
        }

        self::$cacheScope = $scope;
        self::$languageOptionsCache = null;
        self::$personOptionsCache = null;
        self::$institutionOptionsCache = null;
        self::$tagOptionsCache = [];
    }

    /**
     * @return array<int, Component>
     */
    public static function components(?string $fixedTimezone = null, bool $enforceRequired = true): array
    {
        return [
            Section::make(__('Maklumat Majlis'))
                ->schema([
                    TextInput::make('title')
                        ->label(__('Tajuk Majlis'))
                        ->required($enforceRequired)
                        ->maxLength(255),
                    Select::make('event_category_ids')
                        ->label(__('Jenis Majlis'))
                        ->placeholder(__('Pilih kategori…'))
                        ->options(self::eventCategoryOptions())
                        ->multiple()
                        ->closeOnSelect()
                        ->searchable()
                        ->live()
                        ->required($enforceRequired)
                        ->columnSpanFull(),
                    RichEditor::make('description')
                        ->label(__('Keterangan'))
                        ->json()
                        ->columnSpanFull(),
                    DatePicker::make('event_date')
                        ->label(__('Tarikh'))
                        ->native()
                        ->live()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('prayer_time', null);
                        })
                        ->required($enforceRequired),
                    Select::make('prayer_time')
                        ->label(__('Waktu'))
                        ->options(fn (Get $get): array => self::eventPrayerTimeOptions(
                            $get('event_date'),
                            $get('timezone'),
                        ))
                        ->default(EventPrayerTime::LainWaktu->value)
                        ->afterStateUpdated(function (mixed $state, Set $set): void {
                            if ($state !== EventPrayerTime::LainWaktu->value) {
                                $set('custom_time', null);
                            }
                        })
                        ->required($enforceRequired)
                        ->live(),
                    TimePicker::make('custom_time')
                        ->label(__('Masa Mula'))
                        ->helperText(__('Pilih masa mula majlis'))
                        ->native()
                        ->seconds(false)
                        ->minutesStep(5)
                        ->required($enforceRequired ? fn (Get $get): bool => $get('prayer_time') === EventPrayerTime::LainWaktu->value : false)
                        ->visible(fn (Get $get): bool => $get('prayer_time') === EventPrayerTime::LainWaktu->value),
                    TimePicker::make('end_time')
                        ->label(__('Masa Akhir'))
                        ->helperText(__('Pilihan: Bila majlis dijangka tamat.'))
                        ->native()
                        ->seconds(false)
                        ->minutesStep(5),
                    self::timezoneField($fixedTimezone, $enforceRequired),
                    Select::make('event_format')
                        ->label(__('Format Majlis'))
                        ->options(EventFormat::class)
                        ->live()
                        ->required($enforceRequired),
                    Select::make('visibility')
                        ->label(__('Keterlihatan'))
                        ->options(EventVisibility::class)
                        ->required($enforceRequired),
                    TextInput::make('event_url')
                        ->label(__('Pautan Majlis'))
                        ->url()
                        ->maxLength(255),
                    TextInput::make('live_url')
                        ->label(__('Pautan Siaran Langsung'))
                        ->url()
                        ->maxLength(255),
                    TextInput::make('recording_url')
                        ->label(__('Pautan Rakaman'))
                        ->url()
                        ->maxLength(255),
                ])
                ->columns(['default' => 1, 'sm' => 2]),
            Section::make(__('Audience & Language'))
                ->schema([
                    Select::make('gender')
                        ->label(__('Jantina'))
                        ->options(EventGenderRestriction::class)
                        ->required($enforceRequired),
                    Select::make('age_group')
                        ->label(__('Peringkat Umur'))
                        ->placeholder(__('Pilih peringkat umur'))
                        ->options(EventAgeGroup::class)
                        ->multiple()
                        ->closeOnSelect()
                        ->live()
                        ->afterStateUpdated(function (mixed $state, Set $set): void {
                            $ageGroups = self::normalizeAgeGroupState($state);

                            if (in_array(EventAgeGroup::AllAges->value, $ageGroups, true) && count($ageGroups) > 1) {
                                $ageGroups = array_values(array_filter(
                                    $ageGroups,
                                    fn (string $ageGroup): bool => $ageGroup !== EventAgeGroup::AllAges->value,
                                ));

                                $set('age_group', $ageGroups);
                            }

                            if (self::shouldForceChildrenAllowed($ageGroups)) {
                                $set('children_allowed', true);
                            }
                        })
                        ->required($enforceRequired),
                    Toggle::make('children_allowed')
                        ->label(__('Kanak-kanak Dibenarkan'))
                        ->helperText(__('Adakah ibu bapa boleh membawa anak kecil ke majlis ini?'))
                        ->default(true)
                        ->disabled(fn (Get $get): bool => self::shouldForceChildrenAllowed($get('age_group'))),
                    Toggle::make('is_muslim_only')
                        ->label(__('Terbuka untuk Muslim Sahaja'))
                        ->helperText(__('Pilih jika majlis ini hanya terbuka untuk penganut agama Islam.'))
                        ->default(false),
                    Select::make('language_ids')
                        ->label(__('Bahasa'))
                        ->placeholder(__('Pilih bahasa'))
                        ->options(fn (): array => self::languageOptions())
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'sm' => 2]),
            Section::make(__('Kategori & Rujukan'))
                ->schema([
                    Select::make('domain_tags')
                        ->label(__('Kategori'))
                        ->placeholder(__('Pilih kategori…'))
                        ->options(fn (): array => self::tagOptions(EventTaxonomyCode::Domain))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->closeOnSelect(),
                    Select::make('discipline_tags')
                        ->label(__('Bidang Ilmu'))
                        ->placeholder(__('Pilih atau taip untuk tambah bidang…'))
                        ->options(fn (): array => self::tagOptions(EventTaxonomyCode::Discipline))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->closeOnSelect()
                        ->createOptionForm([
                            TextInput::make('name')
                                ->label(__('Nama Bidang'))
                                ->required()
                                ->maxLength(255),
                        ])
                        ->createOptionUsing(fn (array $data): string => self::createPendingTag($data, EventTaxonomyCode::Discipline)),
                    Select::make('source_tags')
                        ->label(__('Sumber Utama'))
                        ->placeholder(__('Pilih sumber…'))
                        ->options(fn (): array => self::tagOptions(EventTaxonomyCode::Source))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->closeOnSelect(),
                    Select::make('issue_tags')
                        ->label(__('Tema / Isu'))
                        ->placeholder(__('Pilih atau taip untuk tambah tema…'))
                        ->options(fn (): array => self::tagOptions(EventTaxonomyCode::Issue))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->closeOnSelect()
                        ->createOptionForm([
                            TextInput::make('name')
                                ->label(__('Nama Tema'))
                                ->required()
                                ->maxLength(255),
                        ])
                        ->createOptionUsing(fn (array $data): string => self::createPendingTag($data, EventTaxonomyCode::Issue)),
                    Select::make('reference_ids')
                        ->label(__('Rujukan Kitab / Buku'))
                        ->placeholder(__('Cari atau pilih rujukan…'))
                        ->options(fn (): array => Reference::query()
                            ->orderBy('title')
                            ->get(['id', 'title', 'parent_id', 'metadata'])
                            ->mapWithKeys(fn (Reference $reference): array => [(string) $reference->id => $reference->displayTitle()])
                            ->all())
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->createOptionForm([
                            TextInput::make('title')
                                ->label(__('Tajuk Kitab / Buku'))
                                ->required()
                                ->maxLength(255),
                            TextInput::make('author')
                                ->label(__('Pengarang'))
                                ->maxLength(255),
                            Select::make('type')
                                ->label(__('Jenis'))
                                ->options(ReferenceType::class)
                                ->default(ReferenceType::Book->value),
                            TextInput::make('publication_year')
                                ->label(__('Tahun Terbitan'))
                                ->numeric()
                                ->minValue(1000)
                                ->maxValue((int) now()->addYears(1)->format('Y')),
                            TextInput::make('publisher')
                                ->label(__('Penerbit'))
                                ->maxLength(255),
                            TextInput::make('reference_url')
                                ->label(__('Pautan Rujukan'))
                                ->url()
                                ->maxLength(255),
                            SpatieMediaLibraryFileUpload::make('front_cover')
                                ->label(__('Muka Depan'))
                                ->collection('front_cover')
                                ->image()
                                ->imageEditor()
                                ->conversion('thumb')
                                ->responsiveImages(),
                            SpatieMediaLibraryFileUpload::make('back_cover')
                                ->label(__('Muka Belakang'))
                                ->collection('back_cover')
                                ->image()
                                ->imageEditor()
                                ->conversion('thumb')
                                ->responsiveImages(),
                            SpatieMediaLibraryFileUpload::make('gallery')
                                ->label(__('Galeri'))
                                ->collection('gallery')
                                ->multiple()
                                ->image()
                                ->imageEditor()
                                ->conversion('gallery_thumb')
                                ->responsiveImages()
                                ->maxFiles(5),
                            Textarea::make('description')
                                ->label(__('Keterangan Ringkas'))
                                ->rows(3)
                                ->columnSpanFull(),
                        ])
                        ->createOptionUsing(function (array $data, Schema $schema): string {
                            $reference = Reference::create([
                                'title' => $data['title'],
                                'slug' => app(GenerateReferenceSlugAction::class)->handle((string) ($data['title'] ?? '')),
                                'author' => $data['author'] ?? null,
                                'type' => $data['type'] ?? ReferenceType::Book->value,
                                'year' => filled($data['publication_year'] ?? null) ? (string) $data['publication_year'] : null,
                                'publisher' => $data['publisher'] ?? null,
                                'description' => $data['description'] ?? null,
                                'is_canonical' => false,
                                'status' => 'pending',
                            ]);

                            $schema->model($reference)->saveRelationships();

                            if (! empty($data['reference_url'])) {
                                $reference->socialProfiles()->create([
                                    'platform' => 'website',
                                    'url' => $data['reference_url'],
                                ]);
                            }

                            return (string) $reference->getKey();
                        })
                        ->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'sm' => 2]),
            Section::make(__('Organizer & Location'))
                ->schema([
                    Section::make(__('Penganjur'))
                        ->schema([
                            Hidden::make('primary_organizer_id'),
                            Radio::make('primary_organizer_kind')
                                ->label(__('Jenis Penganjur'))
                                ->options([
                                    'institution' => __('Institusi'),
                                    'person' => __('Penceramah'),
                                ])
                                ->default('institution')
                                ->inline()
                                ->live()
                                ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                    if ($state === 'institution') {
                                        $organizerId = self::normalizedString($get('primary_organizer_institution_id'))
                                            ?? self::resolvedPrimaryOrganizerInstitutionId($get('primary_organizer_id'));

                                        $set('primary_organizer_id', $organizerId);

                                        if ($get('location_same_as_institution') !== false) {
                                            $set('location_type', 'institution');
                                            $set('location_institution_id', $organizerId);
                                            $set('location_venue_id', null);
                                        }

                                        return;
                                    }

                                    $organizerId = self::normalizedString($get('primary_organizer_person_id'));
                                    $set('primary_organizer_id', $organizerId);
                                    $set('location_same_as_institution', false);
                                }),
                            Select::make('primary_organizer_institution_id')
                                ->label(__('Institusi'))
                                ->options(fn (): array => self::institutionOptions())
                                ->searchable()
                                ->preload()
                                ->visible(fn (Get $get): bool => self::selectedPrimaryOrganizerKind(
                                    $get('primary_organizer_kind'),
                                    $get('primary_organizer_id'),
                                ) === 'institution')
                                ->required($enforceRequired ? fn (Get $get): bool => self::selectedPrimaryOrganizerKind(
                                    $get('primary_organizer_kind'),
                                    $get('primary_organizer_id'),
                                ) === 'institution' && ! filled($get('primary_organizer_id')) : false)
                                ->live()
                                ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                    $set('primary_organizer_id', self::normalizedString($state));

                                    if (self::selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')) !== 'institution'
                                        || $get('location_same_as_institution') === false) {
                                        return;
                                    }

                                    $set('location_type', 'institution');
                                    $set('location_institution_id', $state);
                                    $set('location_venue_id', null);
                                })
                                ->createOptionForm(InstitutionFormSchema::createOptionForm(includeLocationPicker: true))
                                ->createOptionUsing(fn (array $data, ?Schema $schema = null): string => InstitutionFormSchema::createOptionUsing($data, $schema)),
                            Select::make('primary_organizer_person_id')
                                ->label(__('Penceramah'))
                                ->options(fn (): array => self::personOptions())
                                ->searchable()
                                ->preload()
                                ->visible(fn (Get $get): bool => self::selectedPrimaryOrganizerKind(
                                    $get('primary_organizer_kind'),
                                    $get('primary_organizer_id'),
                                ) === 'person')
                                ->required($enforceRequired ? fn (Get $get): bool => self::selectedPrimaryOrganizerKind(
                                    $get('primary_organizer_kind'),
                                    $get('primary_organizer_id'),
                                ) === 'person' && ! filled($get('primary_organizer_id')) : false)
                                ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                                    $set('primary_organizer_id', self::normalizedString($state));
                                    $set('location_same_as_institution', false);

                                    if (! is_string($state) || $state === '') {
                                        return;
                                    }

                                    $currentPersons = self::normalizeStringList($get('person_ids'));

                                    if (! in_array($state, $currentPersons, true)) {
                                        $currentPersons[] = $state;
                                        $set('person_ids', $currentPersons);
                                    }
                                })
                                ->createOptionForm(PersonFormSchema::createOptionForm())
                                ->createOptionUsing(fn (array $data, ?Schema $schema = null): string => PersonFormSchema::createOptionUsing($data, $schema)),
                            Select::make('series_ids')
                                ->label(__('Siri'))
                                ->placeholder(__('Pilih siri'))
                                ->options(fn (): array => Series::query()->orderBy('title')->pluck('title', 'id')->all())
                                ->multiple()
                                ->searchable()
                                ->preload(),
                        ]),
                    Section::make(__('Lokasi'))
                        ->visible(fn (Get $get): bool => self::shouldShowLocationSection(
                            self::selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')),
                            $get('event_format'),
                        ))
                        ->schema([
                            Toggle::make('location_same_as_institution')
                                ->label(__('Sama seperti institusi penganjur'))
                                ->default(true)
                                ->inline(false)
                                ->visible(fn (Get $get): bool => self::selectedPrimaryOrganizerKind(
                                    $get('primary_organizer_kind'),
                                    $get('primary_organizer_id'),
                                ) === 'institution')
                                ->live()
                                ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                    if ($state === false) {
                                        return;
                                    }

                                    $set('location_type', 'institution');
                                    $set('location_institution_id', self::resolvedPrimaryOrganizerInstitutionId($get('primary_organizer_id'))
                                        ?? $get('primary_organizer_institution_id'));
                                    $set('location_venue_id', null);
                                }),
                            Radio::make('location_type')
                                ->label(__('Jenis Lokasi'))
                                ->options([
                                    'institution' => __('Institusi'),
                                    'venue' => __('Tempat'),
                                ])
                                ->inline()
                                ->default('institution')
                                ->visible(fn (Get $get): bool => self::requiresSeparateLocationChoice(
                                    self::selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')),
                                    $get('location_same_as_institution'),
                                ))
                                ->required($enforceRequired ? fn (Get $get): bool => self::requiresSeparateLocationChoice(
                                    self::selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')),
                                    $get('location_same_as_institution'),
                                ) : false)
                                ->live()
                                ->afterStateUpdated(function (Set $set, mixed $state): void {
                                    if ($state === 'venue') {
                                        $set('location_institution_id', null);
                                        $set('space_ids', []);

                                        return;
                                    }

                                    if ($state === 'institution') {
                                        $set('location_venue_id', null);
                                    }
                                }),
                            Select::make('location_institution_id')
                                ->label(__('Institusi'))
                                ->options(fn (): array => self::institutionOptions())
                                ->searchable()
                                ->preload()
                                ->visible(fn (Get $get): bool => self::requiresSeparateLocationChoice(
                                    self::selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')),
                                    $get('location_same_as_institution'),
                                ) && self::resolvedLocationType(
                                    self::selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')),
                                    $get('location_same_as_institution'),
                                    $get('location_type'),
                                ) === 'institution')
                                ->required($enforceRequired ? fn (Get $get): bool => self::requiresSeparateLocationChoice(
                                    self::selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')),
                                    $get('location_same_as_institution'),
                                ) && self::resolvedLocationType(
                                    self::selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')),
                                    $get('location_same_as_institution'),
                                    $get('location_type'),
                                ) === 'institution' : false)
                                ->live()
                                ->createOptionForm(InstitutionFormSchema::createOptionForm(includeLocationPicker: true))
                                ->createOptionUsing(fn (array $data, ?Schema $schema = null): string => InstitutionFormSchema::createOptionUsing($data, $schema)),
                            Select::make('location_venue_id')
                                ->label(__('Lokasi'))
                                ->options(fn (): array => self::venueOptions())
                                ->searchable()
                                ->preload()
                                ->visible(fn (Get $get): bool => self::requiresSeparateLocationChoice(
                                    self::selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')),
                                    $get('location_same_as_institution'),
                                ) && self::resolvedLocationType(
                                    self::selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')),
                                    $get('location_same_as_institution'),
                                    $get('location_type'),
                                ) === 'venue')
                                ->required($enforceRequired ? fn (Get $get): bool => self::requiresSeparateLocationChoice(
                                    self::selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')),
                                    $get('location_same_as_institution'),
                                ) && self::resolvedLocationType(
                                    self::selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')),
                                    $get('location_same_as_institution'),
                                    $get('location_type'),
                                ) === 'venue' : false)
                                ->createOptionForm(VenueFormSchema::createOptionForm(includeLocationPicker: true))
                                ->createOptionUsing(fn (array $data, ?Schema $schema = null): string => VenueFormSchema::createOptionUsing($data, $schema)),
                            Select::make('space_ids')
                                ->label(__('Ruang'))
                                ->helperText(__('Pilih satu atau lebih ruang (cth: Dewan Utama, Ruang Solat).'))
                                ->placeholder(__('Pilih ruang…'))
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->options(function (Get $get): array {
                                    $locationType = self::resolvedLocationType(
                                        self::selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')),
                                        $get('location_same_as_institution'),
                                        $get('location_type'),
                                    );

                                    if ($locationType === 'venue') {
                                        return self::spaceOptionsForVenue(self::normalizedString($get('location_venue_id')));
                                    }

                                    return self::spaceOptionsForInstitution(self::resolvedLocationInstitutionId(
                                        self::selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')),
                                        $get('primary_organizer_id'),
                                        $get('primary_organizer_institution_id'),
                                        $get('location_same_as_institution'),
                                        $get('location_type'),
                                        $get('location_institution_id'),
                                    ));
                                })
                                ->visible(function (Get $get): bool {
                                    $locationType = self::resolvedLocationType(
                                        self::selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')),
                                        $get('location_same_as_institution'),
                                        $get('location_type'),
                                    );

                                    return $locationType === 'venue'
                                        ? self::normalizedString($get('location_venue_id')) !== null
                                        : self::resolvedLocationInstitutionId(
                                            self::selectedPrimaryOrganizerKind($get('primary_organizer_kind'), $get('primary_organizer_id')),
                                            $get('primary_organizer_id'),
                                            $get('primary_organizer_institution_id'),
                                            $get('location_same_as_institution'),
                                            $get('location_type'),
                                            $get('location_institution_id'),
                                        ) !== null;
                                }),
                        ]),
                ])
                ->columns(['default' => 1, 'sm' => 2]),
            Section::make(__('Penceramah & Peranan'))
                ->schema([
                    Select::make('person_ids')
                        ->label(__('Pilih Penceramah'))
                        ->placeholder(__('Pilih Penceramah'))
                        ->options(fn (): array => self::personOptions())
                        ->required($enforceRequired ? fn (Get $get): bool => self::requiresPersonsForCategories($get('event_category_ids')) : false)
                        ->multiple()
                        ->closeOnSelect()
                        ->searchable()
                        ->preload()
                        ->createOptionForm(PersonFormSchema::createOptionForm())
                        ->createOptionUsing(fn (array $data, ?Schema $schema = null): string => PersonFormSchema::createOptionUsing($data, $schema))
                        ->helperText(fn (Get $get): string => self::requiresPersonsForCategories($get('event_category_ids'))
                            ? __('Sekurang-kurangnya seorang penceramah diperlukan untuk jenis majlis ini.')
                            : __('Kosongkan jika majlis ini tidak mempunyai penceramah khusus.')),
                    Repeater::make('other_key_people')
                        ->label(__('Peranan Lain'))
                        ->helperText(__('Tambahkan moderator, imam, khatib, bilal, atau PIC jika berkenaan.'))
                        ->default([])
                        ->schema([
                            Select::make('role_code')
                                ->label(__('Peranan'))
                                ->options(EventKeyPersonRole::nonSpeakerOptions())
                                ->required($enforceRequired),
                            Select::make('involveable_id')
                                ->label(__('Pautkan Profil Penceramah'))
                                ->options(fn (): array => self::personOptions())
                                ->searchable()
                                ->preload()
                                ->live()
                                ->afterStateUpdated(function (Set $set, mixed $state): void {
                                    $set('display_name', null);
                                    $set('involveable_type', filled($state) ? 'person' : null);
                                })
                                ->createOptionForm(PersonFormSchema::createOptionForm())
                                ->createOptionUsing(fn (array $data, ?Schema $schema = null): string => PersonFormSchema::createOptionUsing($data, $schema)),
                            Hidden::make('involveable_type'),
                            TextInput::make('display_name')
                                ->label(__('Nama Paparan'))
                                ->required($enforceRequired ? fn (Get $get): bool => blank($get('involveable_id')) : false)
                                ->disabled(fn (Get $get): bool => filled($get('involveable_id')))
                                ->dehydrated(fn (Get $get): bool => blank($get('involveable_id')))
                                ->helperText(__('Isi nama jika tiada profil penceramah dipautkan.'))
                                ->maxLength(255),
                            Select::make('visibility')
                                ->label(__('Keterlihatan'))
                                ->options(['public' => __('Awam'), 'private' => __('Peribadi')])
                                ->default('public')
                                ->required($enforceRequired),
                            Textarea::make('notes')
                                ->label(__('Nota Peranan'))
                                ->rows(2)
                                ->maxLength(500),
                        ])
                        ->addActionLabel(__('Tambah Peranan'))
                        ->columns(2)
                        ->columnSpanFull(),
                ])
                ->columns(1),
        ];
    }

    private static function timezoneField(?string $fixedTimezone, bool $enforceRequired = true): Component
    {
        if (! is_string($fixedTimezone) || $fixedTimezone === '') {
            return TextInput::make('timezone')
                ->label(__('Timezone'))
                ->required($enforceRequired)
                ->maxLength(64);
        }

        return Hidden::make('timezone')
            ->default($fixedTimezone)
            ->required($enforceRequired)
            ->afterStateHydrated(static function (Hidden $component) use ($fixedTimezone): void {
                $component->state($fixedTimezone);
            })
            ->dehydrateStateUsing(static fn (): string => $fixedTimezone);
    }

    /**
     * @return array<string, string>
     */
    private static function eventPrayerTimeOptions(mixed $eventDate = null, mixed $timezone = null): array
    {
        return collect(EventPrayerTime::cases())
            ->filter(function (EventPrayerTime $eventPrayerTime) use ($eventDate, $timezone): bool {
                if ($eventDate === null || $eventDate === '') {
                    return ! in_array($eventPrayerTime, [
                        EventPrayerTime::SebelumJumaat,
                        EventPrayerTime::SelepasJumaat,
                        EventPrayerTime::SelepasTarawih,
                    ], true);
                }

                $resolvedDate = self::parseEventDate($eventDate, $timezone);

                if (! $resolvedDate instanceof Carbon) {
                    return true;
                }

                if ($eventPrayerTime === EventPrayerTime::SebelumJumaat || $eventPrayerTime === EventPrayerTime::SelepasJumaat) {
                    return $resolvedDate->isFriday();
                }

                if ($eventPrayerTime === EventPrayerTime::SelepasTarawih) {
                    return self::isRamadhan($resolvedDate);
                }

                return true;
            })
            ->mapWithKeys(fn (EventPrayerTime $eventPrayerTime): array => [$eventPrayerTime->value => $eventPrayerTime->getLabel()])
            ->toArray();
    }

    /**
    /**
     * @return array<string, string>
     */
    private static function eventCategoryOptions(): array
    {
        return app(EventCategoryCatalog::class)->options();
    }

    /**
     * Package EventTerm options for a taxonomy code (ADR-011).
     *
     * @return array<string, string>
     */
    private static function tagOptions(EventTaxonomyCode $type): array
    {
        self::ensureCacheScope();

        if (array_key_exists($type->value, self::$tagOptionsCache)) {
            return self::$tagOptionsCache[$type->value];
        }

        $taxonomy = EventTaxonomy::query()
            ->where('code', $type->value)
            ->where('is_active', true)
            ->first();

        if ($taxonomy === null) {
            return self::$tagOptionsCache[$type->value] = [];
        }

        return self::$tagOptionsCache[$type->value] = EventTerm::query()
            ->where('event_taxonomy_id', $taxonomy->getKey())
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (EventTerm $term): array => [(string) $term->getKey() => (string) $term->name])
            ->all();
    }

    /**
     * @param  array{name: string}  $data
     */
    private static function createPendingTag(array $data, EventTaxonomyCode $type): string
    {
        $name = trim((string) ($data['name'] ?? ''));
        $code = Str::slug($name);

        if ($name === '' || $code === '') {
            return '';
        }

        $taxonomy = EventTaxonomy::query()->firstOrCreate(
            ['code' => $type->value],
            [
                'name' => $type->label(),
                'description' => $type->description(),
                'is_hierarchical' => false,
                'is_active' => true,
            ],
        );

        $term = EventTerm::query()->firstOrCreate(
            [
                'event_taxonomy_id' => $taxonomy->getKey(),
                'code' => $code,
            ],
            [
                'name' => $name,
                'sort_order' => 0,
                'is_active' => true,
            ],
        );

        return (string) $term->getKey();
    }

    /**
     * @return array<string, string>
     */
    private static function venueOptions(): array
    {
        return Venue::query()
            ->whereIn('status', ['verified', 'pending'])
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function requiresPersonsForCategories(mixed $categoryIds): bool
    {
        if ($categoryIds instanceof Collection) {
            $categoryIds = $categoryIds->all();
        }

        if (! is_array($categoryIds)) {
            $categoryIds = [$categoryIds];
        }

        return app(EventCategoryPolicyResolver::class)->requiresSpeaker(
            app(EventCategoryCatalog::class)->validateTermIds($categoryIds),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function institutionOptions(): array
    {
        self::ensureCacheScope();

        return self::$institutionOptionsCache ??= Institution::query()
            ->whereIn('status', ['verified', 'pending'])
            ->orderBy('name')
            ->with('names')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Institution $institution): array => [(string) $institution->id => $institution->display_name])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function personOptions(): array
    {
        self::ensureCacheScope();

        return self::$personOptionsCache ??= Person::query()
            ->whereIn('status', ['verified', 'pending'])
            ->orderBy('name')
            ->with('titleAssignments.title.category')
            ->get()
            ->mapWithKeys(fn (Person $person): array => [(string) $person->id => $person->formatted_name])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function languageOptions(): array
    {
        self::ensureCacheScope();

        return self::$languageOptionsCache ??= app(SelectionCatalogCache::class)->languageOptions('id');
    }

    /**
     * @return array<string, string>
     */
    private static function spaceOptionsForInstitution(?string $institutionId): array
    {
        if ($institutionId === null) {
            return [];
        }

        return app(SpaceEligibilityResolver::class)->institutionQuery($institutionId)
            ->where('status', 'active')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function spaceOptionsForVenue(?string $venueId): array
    {
        if ($venueId === null) {
            return [];
        }

        return app(SpaceEligibilityResolver::class)->venueQuery($venueId)
            ->where('status', 'active')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function shouldShowLocationSection(mixed $organizerType, mixed $eventFormat): bool
    {
        return filled($organizerType) && ! self::isOnlineEventFormat($eventFormat);
    }

    private static function requiresSeparateLocationChoice(mixed $organizerType, mixed $sameAsInstitution): bool
    {
        return $organizerType === 'person' || $sameAsInstitution === false;
    }

    private static function resolvedLocationType(mixed $organizerType, mixed $sameAsInstitution, mixed $locationType): ?string
    {
        if ($organizerType === 'institution' && $sameAsInstitution !== false) {
            return 'institution';
        }

        return in_array($locationType, ['institution', 'venue'], true) ? $locationType : null;
    }

    private static function resolvedLocationInstitutionId(
        mixed $organizerType,
        mixed $primaryOrganizerId,
        mixed $primaryOrganizerInstitutionId,
        mixed $sameAsInstitution,
        mixed $locationType,
        mixed $locationInstitutionId,
    ): ?string {
        if ($organizerType === 'institution' && $sameAsInstitution !== false) {
            return self::normalizedString($primaryOrganizerInstitutionId)
                ?? self::resolvedPrimaryOrganizerInstitutionId($primaryOrganizerId);
        }

        if (self::resolvedLocationType($organizerType, $sameAsInstitution, $locationType) !== 'institution') {
            return null;
        }

        return self::normalizedString($locationInstitutionId);
    }

    private static function selectedPrimaryOrganizerKind(mixed $organizerKind, mixed $primaryOrganizerId): ?string
    {
        $normalizedKind = in_array($organizerKind, ['institution', 'person'], true)
            ? $organizerKind
            : null;

        return $normalizedKind ?? self::resolvedPrimaryOrganizerType($primaryOrganizerId);
    }

    private static function resolvedPrimaryOrganizerInstitutionId(mixed $primaryOrganizerId): ?string
    {
        return self::resolvedPrimaryOrganizerType($primaryOrganizerId) === 'institution'
            ? self::normalizedString($primaryOrganizerId)
            : null;
    }

    private static function resolvedPrimaryOrganizerType(mixed $primaryOrganizerId): ?string
    {
        $organizerId = self::normalizedString($primaryOrganizerId);

        if ($organizerId === null) {
            return null;
        }

        if (Institution::query()->whereKey($organizerId)->exists()) {
            return 'institution';
        }

        if (Person::query()->whereKey($organizerId)->exists()) {
            return 'person';
        }

        return null;
    }

    private static function isOnlineEventFormat(mixed $eventFormat): bool
    {
        $normalizedEventFormat = $eventFormat instanceof EventFormat
            ? $eventFormat
            : EventFormat::tryFrom((string) $eventFormat);

        return $normalizedEventFormat === EventFormat::Online;
    }

    private static function normalizedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @return array<int, string>
     */
    private static function normalizeAgeGroupState(mixed $state): array
    {
        if ($state instanceof Collection) {
            $state = $state->all();
        }

        if (! is_array($state)) {
            $state = [$state];
        }

        return collect($state)
            ->map(function (mixed $ageGroup): ?string {
                if ($ageGroup instanceof EventAgeGroup) {
                    return $ageGroup->value;
                }

                if (is_string($ageGroup) && filled($ageGroup)) {
                    return $ageGroup;
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();
    }

    private static function shouldForceChildrenAllowed(mixed $ageGroups): bool
    {
        $normalizedAgeGroups = self::normalizeAgeGroupState($ageGroups);

        return in_array(EventAgeGroup::Children->value, $normalizedAgeGroups, true)
            || in_array(EventAgeGroup::AllAges->value, $normalizedAgeGroups, true);
    }

    private static function parseEventDate(mixed $eventDate, mixed $timezone): ?Carbon
    {
        if (! is_string($eventDate) || trim($eventDate) === '') {
            return null;
        }

        $resolvedTimezone = is_string($timezone) && trim($timezone) !== ''
            ? trim($timezone)
            : 'Asia/Kuala_Lumpur';

        return Carbon::parse($eventDate, $resolvedTimezone)->startOfDay();
    }

    private static function isRamadhan(Carbon $date): bool
    {
        $year = $date->year;

        $ramadhanPeriods = [
            2026 => ['start' => '02-18', 'end' => '03-19'],
            2027 => ['start' => '02-07', 'end' => '03-08'],
            2028 => ['start' => '01-27', 'end' => '02-25'],
            2029 => ['start' => '01-16', 'end' => '02-13'],
            2030 => ['start' => '01-05', 'end' => '02-03'],
        ];

        if (! isset($ramadhanPeriods[$year])) {
            return false;
        }

        $period = $ramadhanPeriods[$year];
        $startDate = Carbon::parse("{$year}-{$period['start']}", $date->timezone)->startOfDay();
        $endDate = Carbon::parse("{$year}-{$period['end']}", $date->timezone)->endOfDay();

        return $date->between($startDate, $endDate);
    }

    /**
     * @return list<string>
     */
    private static function normalizeStringList(mixed $values): array
    {
        if ($values instanceof Collection) {
            $values = $values->all();
        }

        if (! is_array($values)) {
            $values = [$values];
        }

        return collect($values)
            ->map(fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null)
            ->filter()
            ->values()
            ->all();
    }
}
