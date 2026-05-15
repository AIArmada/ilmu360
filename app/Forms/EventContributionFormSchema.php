<?php

namespace App\Forms;

use App\Actions\References\GenerateReferenceSlugAction;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\ReferenceType;
use App\Enums\TagType;
use App\Forms\Components\Select;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Series;
use App\Models\Space;
use App\Models\Speaker;
use App\Models\Tag;
use App\Models\Venue;
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
use Nnjeim\World\Models\Language;

class EventContributionFormSchema
{
    /**
     * @return array<int, Component>
     */
    public static function components(?string $fixedTimezone = null): array
    {
        return [
            Section::make(__('Maklumat Majlis'))
                ->schema([
                    TextInput::make('title')
                        ->label(__('Tajuk Majlis'))
                        ->required()
                        ->maxLength(255),
                    Select::make('event_type')
                        ->label(__('Jenis Majlis'))
                        ->options(self::eventTypeOptions())
                        ->multiple()
                        ->closeOnSelect()
                        ->searchable()
                        ->live()
                        ->required()
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
                        ->required(),
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
                        ->required()
                        ->live(),
                    TimePicker::make('custom_time')
                        ->label(__('Masa Mula'))
                        ->helperText(__('Pilih masa mula majlis'))
                        ->native()
                        ->seconds(false)
                        ->minutesStep(5)
                        ->required(fn (Get $get): bool => $get('prayer_time') === EventPrayerTime::LainWaktu->value)
                        ->visible(fn (Get $get): bool => $get('prayer_time') === EventPrayerTime::LainWaktu->value),
                    TimePicker::make('end_time')
                        ->label(__('Masa Akhir'))
                        ->helperText(__('Pilihan: Bila majlis dijangka tamat.'))
                        ->native()
                        ->seconds(false)
                        ->minutesStep(5),
                    self::timezoneField($fixedTimezone),
                    Select::make('event_format')
                        ->label(__('Format Majlis'))
                        ->options(EventFormat::class)
                        ->live()
                        ->required(),
                    Select::make('visibility')
                        ->label(__('Keterlihatan'))
                        ->options(EventVisibility::class)
                        ->required(),
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
                        ->required(),
                    Select::make('age_group')
                        ->label(__('Peringkat Umur'))
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
                        ->required(),
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
                        ->options(fn (): array => Language::query()->orderBy('name')->pluck('name', 'id')->all())
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
                        ->options(fn (): array => self::tagOptions(TagType::Domain))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->closeOnSelect(),
                    Select::make('discipline_tags')
                        ->label(__('Bidang Ilmu'))
                        ->options(fn (): array => self::tagOptions(TagType::Discipline))
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
                        ->createOptionUsing(fn (array $data): string => self::createPendingTag($data, TagType::Discipline)),
                    Select::make('source_tags')
                        ->label(__('Sumber Utama'))
                        ->options(fn (): array => self::tagOptions(TagType::Source))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->closeOnSelect(),
                    Select::make('issue_tags')
                        ->label(__('Tema / Isu'))
                        ->options(fn (): array => self::tagOptions(TagType::Issue))
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
                        ->createOptionUsing(fn (array $data): string => self::createPendingTag($data, TagType::Issue)),
                    Select::make('reference_ids')
                        ->label(__('Rujukan Kitab / Buku'))
                        ->options(fn (): array => Reference::query()
                            ->orderBy('title')
                            ->get(['id', 'title', 'parent_reference_id', 'part_type', 'part_number', 'part_label'])
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
                                'publication_year' => filled($data['publication_year'] ?? null) ? (string) $data['publication_year'] : null,
                                'publisher' => $data['publisher'] ?? null,
                                'description' => $data['description'] ?? null,
                                'is_canonical' => false,
                                'status' => 'pending',
                                'is_active' => true,
                            ]);

                            $schema->model($reference)->saveRelationships();

                            if (! empty($data['reference_url'])) {
                                $reference->socialMedia()->create([
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
                            Radio::make('organizer_type')
                                ->label(__('Jenis Penganjur'))
                                ->options([
                                    'institution' => __('Institusi'),
                                    'speaker' => __('Penceramah'),
                                ])
                                ->default('institution')
                                ->inline()
                                ->live(),
                            Select::make('organizer_institution_id')
                                ->label(__('Institusi'))
                                ->options(fn (): array => self::institutionOptions())
                                ->searchable()
                                ->preload()
                                ->visible(fn (Get $get): bool => $get('organizer_type') === 'institution')
                                ->required(fn (Get $get): bool => $get('organizer_type') === 'institution')
                                ->live()
                                ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                    if ($get('organizer_type') !== 'institution' || $get('location_same_as_institution') === false) {
                                        return;
                                    }

                                    $set('location_type', 'institution');
                                    $set('location_institution_id', $state);
                                    $set('location_venue_id', null);
                                })
                                ->createOptionForm(InstitutionFormSchema::createOptionForm(includeLocationPicker: true))
                                ->createOptionUsing(fn (array $data, ?Schema $schema = null): string => InstitutionFormSchema::createOptionUsing($data, $schema)),
                            Select::make('organizer_speaker_id')
                                ->label(__('Penceramah'))
                                ->options(fn (): array => self::speakerOptions())
                                ->searchable()
                                ->preload()
                                ->visible(fn (Get $get): bool => $get('organizer_type') === 'speaker')
                                ->required(fn (Get $get): bool => $get('organizer_type') === 'speaker')
                                ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                                    if (! is_string($state) || $state === '') {
                                        return;
                                    }

                                    $currentSpeakers = self::normalizeStringList($get('speaker_ids'));

                                    if (! in_array($state, $currentSpeakers, true)) {
                                        $currentSpeakers[] = $state;
                                        $set('speaker_ids', $currentSpeakers);
                                    }
                                })
                                ->createOptionForm(SpeakerFormSchema::createOptionForm())
                                ->createOptionUsing(fn (array $data, ?Schema $schema = null): string => SpeakerFormSchema::createOptionUsing($data, $schema)),
                            Select::make('series_ids')
                                ->label(__('Siri'))
                                ->options(fn (): array => Series::query()->orderBy('title')->pluck('title', 'id')->all())
                                ->multiple()
                                ->searchable()
                                ->preload(),
                        ]),
                    Section::make(__('Lokasi'))
                        ->visible(fn (Get $get): bool => self::shouldShowLocationSection($get('organizer_type'), $get('event_format')))
                        ->schema([
                            Toggle::make('location_same_as_institution')
                                ->label(__('Sama seperti institusi penganjur'))
                                ->default(true)
                                ->inline(false)
                                ->visible(fn (Get $get): bool => $get('organizer_type') === 'institution')
                                ->live()
                                ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                    if ($state === false) {
                                        return;
                                    }

                                    $set('location_type', 'institution');
                                    $set('location_institution_id', $get('organizer_institution_id'));
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
                                ->visible(fn (Get $get): bool => self::requiresSeparateLocationChoice($get('organizer_type'), $get('location_same_as_institution')))
                                ->required(fn (Get $get): bool => self::requiresSeparateLocationChoice($get('organizer_type'), $get('location_same_as_institution')))
                                ->live()
                                ->afterStateUpdated(function (Set $set, mixed $state): void {
                                    if ($state === 'venue') {
                                        $set('location_institution_id', null);
                                        $set('space_id', null);

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
                                ->visible(fn (Get $get): bool => self::requiresSeparateLocationChoice($get('organizer_type'), $get('location_same_as_institution'))
                                    && self::resolvedLocationType($get('organizer_type'), $get('location_same_as_institution'), $get('location_type')) === 'institution')
                                ->required(fn (Get $get): bool => self::requiresSeparateLocationChoice($get('organizer_type'), $get('location_same_as_institution'))
                                    && self::resolvedLocationType($get('organizer_type'), $get('location_same_as_institution'), $get('location_type')) === 'institution')
                                ->live()
                                ->createOptionForm(InstitutionFormSchema::createOptionForm(includeLocationPicker: true))
                                ->createOptionUsing(fn (array $data, ?Schema $schema = null): string => InstitutionFormSchema::createOptionUsing($data, $schema)),
                            Select::make('location_venue_id')
                                ->label(__('Lokasi'))
                                ->options(fn (): array => self::venueOptions())
                                ->searchable()
                                ->preload()
                                ->visible(fn (Get $get): bool => self::requiresSeparateLocationChoice($get('organizer_type'), $get('location_same_as_institution'))
                                    && self::resolvedLocationType($get('organizer_type'), $get('location_same_as_institution'), $get('location_type')) === 'venue')
                                ->required(fn (Get $get): bool => self::requiresSeparateLocationChoice($get('organizer_type'), $get('location_same_as_institution'))
                                    && self::resolvedLocationType($get('organizer_type'), $get('location_same_as_institution'), $get('location_type')) === 'venue')
                                ->createOptionForm(VenueFormSchema::createOptionForm(includeLocationPicker: true))
                                ->createOptionUsing(fn (array $data, ?Schema $schema = null): string => VenueFormSchema::createOptionUsing($data, $schema)),
                            Select::make('space_id')
                                ->label(__('Ruang'))
                                ->helperText(__('Pilihan: Pilih ruang tertentu di dalam institusi (cth: Dewan Utama, Ruang Solat).'))
                                ->searchable()
                                ->preload()
                                ->options(fn (Get $get): array => self::spaceOptionsForInstitution(self::resolvedLocationInstitutionId(
                                    $get('organizer_type'),
                                    $get('organizer_institution_id'),
                                    $get('location_same_as_institution'),
                                    $get('location_type'),
                                    $get('location_institution_id'),
                                )))
                                ->visible(fn (Get $get): bool => self::resolvedLocationInstitutionId(
                                    $get('organizer_type'),
                                    $get('organizer_institution_id'),
                                    $get('location_same_as_institution'),
                                    $get('location_type'),
                                    $get('location_institution_id'),
                                ) !== null),
                        ]),
                ])
                ->columns(['default' => 1, 'sm' => 2]),
            Section::make(__('Penceramah & Peranan'))
                ->schema([
                    Select::make('speaker_ids')
                        ->label(__('Pilih Penceramah'))
                        ->options(fn (): array => Speaker::query()
                            ->whereIn('status', ['verified', 'pending'])
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Speaker $speaker): array => [(string) $speaker->id => $speaker->formatted_name])
                            ->all())
                        ->required(fn (Get $get): bool => self::requiresSpeakersForEventTypes($get('event_type')))
                        ->multiple()
                        ->closeOnSelect()
                        ->searchable()
                        ->preload()
                        ->createOptionForm(SpeakerFormSchema::createOptionForm())
                        ->createOptionUsing(fn (array $data, ?Schema $schema = null): string => SpeakerFormSchema::createOptionUsing($data, $schema))
                        ->helperText(fn (Get $get): string => self::requiresSpeakersForEventTypes($get('event_type'))
                            ? __('Sekurang-kurangnya seorang penceramah diperlukan untuk jenis majlis ini.')
                            : __('Kosongkan jika majlis ini tidak mempunyai penceramah khusus.')),
                    Repeater::make('other_key_people')
                        ->label(__('Peranan Lain'))
                        ->helperText(__('Tambahkan moderator, imam, khatib, bilal, atau PIC jika berkenaan.'))
                        ->default([])
                        ->schema([
                            Select::make('role')
                                ->label(__('Peranan'))
                                ->options(EventKeyPersonRole::nonSpeakerOptions())
                                ->required(),
                            Select::make('speaker_id')
                                ->label(__('Pautkan Profil Penceramah'))
                                ->options(fn (): array => Speaker::query()
                                    ->whereIn('status', ['verified', 'pending'])
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn (Speaker $speaker): array => [(string) $speaker->id => $speaker->formatted_name])
                                    ->all())
                                ->searchable()
                                ->preload()
                                ->live()
                                ->afterStateUpdated(fn (Set $set, mixed $state): mixed => filled($state) ? $set('name', null) : null)
                                ->createOptionForm(SpeakerFormSchema::createOptionForm())
                                ->createOptionUsing(fn (array $data, ?Schema $schema = null): string => SpeakerFormSchema::createOptionUsing($data, $schema)),
                            TextInput::make('name')
                                ->label(__('Nama Paparan'))
                                ->required(fn (Get $get): bool => blank($get('speaker_id')))
                                ->disabled(fn (Get $get): bool => filled($get('speaker_id')))
                                ->dehydrated(fn (Get $get): bool => blank($get('speaker_id')))
                                ->helperText(__('Isi nama jika tiada profil penceramah dipautkan.'))
                                ->maxLength(255),
                            Toggle::make('is_public')
                                ->label(__('Papar Secara Awam'))
                                ->default(true),
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

    private static function timezoneField(?string $fixedTimezone): Component
    {
        if (! is_string($fixedTimezone) || $fixedTimezone === '') {
            return TextInput::make('timezone')
                ->label(__('Timezone'))
                ->required()
                ->maxLength(64);
        }

        return Hidden::make('timezone')
            ->default($fixedTimezone)
            ->required()
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
                        EventPrayerTime::SebelumMaghrib,
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

                if ($eventPrayerTime === EventPrayerTime::SebelumMaghrib || $eventPrayerTime === EventPrayerTime::SelepasTarawih) {
                    return self::isRamadhan($resolvedDate);
                }

                return true;
            })
            ->mapWithKeys(fn (EventPrayerTime $eventPrayerTime): array => [$eventPrayerTime->value => $eventPrayerTime->getLabel()])
            ->toArray();
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function eventTypeOptions(): array
    {
        return collect(EventType::cases())
            ->mapToGroups(fn (EventType $type): array => [
                $type->getGroup() => [$type->value => $type->getLabel()],
            ])
            ->map(fn ($group): array => $group->collapse()->toArray())
            ->toArray();
    }

    /**
     * @return array<string, string>
     */
    private static function tagOptions(TagType $type): array
    {
        return Tag::query()
            ->ofType($type)
            ->whereIn('status', ['verified', 'pending'])
            ->ordered()
            ->get()
            ->mapWithKeys(fn (Tag $tag): array => [(string) $tag->id => $tag->getTranslation('name', app()->getLocale())])
            ->toArray();
    }

    /**
     * @param  array{name: string}  $data
     */
    private static function createPendingTag(array $data, TagType $type): string
    {
        $tag = Tag::create([
            'name' => ['ms' => $data['name'], 'en' => $data['name']],
            'type' => $type->value,
            'status' => 'pending',
        ]);

        return (string) $tag->getKey();
    }

    /**
     * @return array<string, string>
     */
    private static function venueOptions(): array
    {
        return Venue::query()
            ->whereIn('status', ['verified', 'pending'])
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function requiresSpeakersForEventTypes(mixed $eventTypes): bool
    {
        if ($eventTypes instanceof Collection) {
            $eventTypes = $eventTypes->all();
        }

        if (! is_array($eventTypes)) {
            $eventTypes = [$eventTypes];
        }

        foreach ($eventTypes as $eventTypeValue) {
            $eventType = $eventTypeValue instanceof EventType
                ? $eventTypeValue
                : EventType::tryFrom((string) $eventTypeValue);

            if ($eventType?->requiresSpeakerByDefault()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private static function institutionOptions(): array
    {
        return Institution::query()
            ->whereIn('status', ['verified', 'pending'])
            ->orderBy('name')
            ->get(['id', 'name', 'nickname'])
            ->mapWithKeys(fn (Institution $institution): array => [(string) $institution->id => $institution->display_name])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function speakerOptions(): array
    {
        return Speaker::query()
            ->whereIn('status', ['verified', 'pending'])
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Speaker $speaker): array => [(string) $speaker->id => $speaker->formatted_name])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function spaceOptionsForInstitution(?string $institutionId): array
    {
        if ($institutionId === null) {
            return [];
        }

        return Space::query()
            ->where('is_active', true)
            ->where(function ($query) use ($institutionId): void {
                $query
                    ->whereHas('institutions', fn ($relatedQuery) => $relatedQuery->where('institutions.id', $institutionId))
                    ->orWhereDoesntHave('institutions');
            })
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
        return $organizerType === 'speaker' || $sameAsInstitution === false;
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
        mixed $organizerInstitutionId,
        mixed $sameAsInstitution,
        mixed $locationType,
        mixed $locationInstitutionId,
    ): ?string {
        if ($organizerType === 'institution' && $sameAsInstitution !== false) {
            return self::normalizedString($organizerInstitutionId);
        }

        if (self::resolvedLocationType($organizerType, $sameAsInstitution, $locationType) !== 'institution') {
            return null;
        }

        return self::normalizedString($locationInstitutionId);
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
