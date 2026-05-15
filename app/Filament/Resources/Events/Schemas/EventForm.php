<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Actions\Events\GenerateEventSlugAction;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\RegistrationMode;
use App\Enums\TagType;
use App\Forms\Components\Select;
use App\Forms\InstitutionFormSchema;
use App\Forms\SpeakerFormSchema;
use App\Forms\VenueFormSchema;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\Tag;
use App\Models\User;
use App\Support\Events\SubmitterContactPresenter;
use App\Support\Timezone\UserDateTimeFormatter;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Nnjeim\World\Models\Language;

class EventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('EventTabs')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Maklumat Majlis')
                            ->icon('heroicon-m-document-text')
                            ->schema([
                                Section::make('Maklumat Utama')
                                    ->columnSpanFull()
                                    ->schema([
                                        TextInput::make('title')
                                            ->label('Tajuk Majlis')
                                            ->required()
                                            ->maxLength(255)
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function (Get $get, Set $set, ?Event $record, ?string $state): void {
                                                self::regenerateSlug($get, $set, $record, $state);
                                            }),
                                        TextInput::make('slug')
                                            ->label('Slug')
                                            ->required(fn (string $operation): bool => $operation !== 'create')
                                            ->hidden(fn (string $operation): bool => $operation === 'create')
                                            ->maxLength(255)
                                            ->unique(ignoreRecord: true)
                                            ->suffixAction(
                                                Action::make('generateSlug')
                                                    ->icon(Heroicon::ArrowPath)
                                                    ->tooltip('Jana semula slug daripada tajuk')
                                                    ->action(function (Get $get, Set $set, ?Event $record): void {
                                                        self::regenerateSlug($get, $set, $record);
                                                    }),
                                            ),
                                        RichEditor::make('description')
                                            ->label('Keterangan')
                                            ->columnSpanFull()
                                            ->maxLength(5000),
                                    ])
                                    ->columns(2),
                                Section::make('Waktu & Format')
                                    ->columnSpanFull()
                                    ->schema([
                                        DatePicker::make('event_date')
                                            ->label('Tarikh')
                                            ->native()
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(function (Get $get, Set $set, ?Event $record): void {
                                                self::regenerateSlug($get, $set, $record);
                                            }),
                                        Select::make('prayer_time')
                                            ->label('Waktu')
                                            ->options(
                                                collect(EventPrayerTime::cases())
                                                    ->mapWithKeys(fn (EventPrayerTime $case): array => [$case->value => $case->getLabel()])
                                                    ->toArray(),
                                            )
                                            ->default(EventPrayerTime::LainWaktu->value)
                                            ->required()
                                            ->live(),
                                        TimePicker::make('custom_time')
                                            ->label('Masa Mula')
                                            ->native()
                                            ->timezone(config('app.timezone'))
                                            ->seconds(false)
                                            ->required(fn (Get $get): bool => $get('prayer_time') === EventPrayerTime::LainWaktu->value)
                                            ->visible(fn (Get $get): bool => $get('prayer_time') === EventPrayerTime::LainWaktu->value),
                                        TimePicker::make('end_time')
                                            ->label('Masa Akhir')
                                            ->native()
                                            ->timezone(config('app.timezone'))
                                            ->seconds(false),
                                        DateTimePicker::make('starts_at')
                                            ->dehydrated(false)
                                            ->visible(false),
                                        DateTimePicker::make('ends_at')
                                            ->dehydrated(false)
                                            ->visible(false),
                                        TextInput::make('timezone')
                                            ->label('Timezone')
                                            ->default('Asia/Kuala_Lumpur')
                                            ->required()
                                            ->maxLength(64)
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function (Get $get, Set $set, ?Event $record): void {
                                                self::regenerateSlug($get, $set, $record);
                                            }),
                                        Select::make('event_format')
                                            ->label('Format Majlis')
                                            ->options(
                                                collect(EventFormat::cases())
                                                    ->mapWithKeys(fn (EventFormat $case): array => [$case->value => $case->label()])
                                                    ->toArray(),
                                            )
                                            ->required(),
                                        Select::make('visibility')
                                            ->label('Keterlihatan')
                                            ->options(EventVisibility::class)
                                            ->default(EventVisibility::Public->value)
                                            ->required(),
                                        TextInput::make('event_url')
                                            ->label('Pautan Majlis')
                                            ->url()
                                            ->maxLength(255),
                                        TextInput::make('live_url')
                                            ->label('Pautan Siaran Langsung')
                                            ->url()
                                            ->maxLength(255),
                                        TextInput::make('recording_url')
                                            ->label('Pautan Rakaman')
                                            ->url()
                                            ->maxLength(255),
                                    ])
                                    ->columns(2),
                                Section::make('Sasaran Peserta')
                                    ->columnSpanFull()
                                    ->schema([
                                        Select::make('gender')
                                            ->label('Jantina')
                                            ->options(EventGenderRestriction::class)
                                            ->required(),
                                        Select::make('age_group')
                                            ->label('Peringkat Umur')
                                            ->options(EventAgeGroup::class)
                                            ->multiple()
                                            ->closeOnSelect()
                                            ->required(),
                                        Toggle::make('children_allowed')
                                            ->label('Kanak-kanak Dibenarkan'),
                                        Toggle::make('is_muslim_only')
                                            ->label('Terbuka untuk Muslim Sahaja'),
                                    ])
                                    ->columns(2),
                                Section::make('Bahasa')
                                    ->columnSpanFull()
                                    ->schema([
                                        Select::make('languages')
                                            ->label('Bahasa')
                                            ->placeholder('Pilih bahasa...')
                                            ->multiple()
                                            ->closeOnSelect()
                                            ->searchable()
                                            ->preload()
                                            ->options(fn (): array => self::getLanguageOptions()),
                                    ]),
                            ]),
                        Tab::make('Kategori & Bidang')
                            ->icon('heroicon-m-tag')
                            ->schema([
                                Section::make('Kategori')
                                    ->columnSpanFull()
                                    ->schema([
                                        Select::make('event_type')
                                            ->label('Jenis Majlis')
                                            ->required()
                                            ->multiple()
                                            ->closeOnSelect()
                                            ->searchable()
                                            ->options(self::getEventTypeOptions()),
                                        Select::make('domain_tags')
                                            ->label('Kategori')
                                            ->helperText('Pilih kategori ceramah utama.')
                                            ->multiple()
                                            ->closeOnSelect()
                                            ->searchable()
                                            ->preload()
                                            ->options(fn (): array => self::getTagOptions(TagType::Domain))
                                            ->getOptionLabelUsing(fn (mixed $value): ?string => self::getTagLabel($value))
                                            ->getOptionLabelsUsing(fn (array $values): array => self::getTagLabels($values)),
                                        Select::make('discipline_tags')
                                            ->label('Bidang Ilmu')
                                            ->multiple()
                                            ->closeOnSelect()
                                            ->searchable()
                                            ->preload()
                                            ->options(fn (): array => self::getTagOptions(TagType::Discipline))
                                            ->getOptionLabelUsing(fn (mixed $value): ?string => self::getTagLabel($value))
                                            ->getOptionLabelsUsing(fn (array $values): array => self::getTagLabels($values))
                                            ->createOptionForm([
                                                TextInput::make('name')
                                                    ->label('Nama Bidang')
                                                    ->required()
                                                    ->maxLength(255),
                                            ])
                                            ->createOptionUsing(fn (array $data): string => self::createPendingTag($data, TagType::Discipline)),
                                        Select::make('source_tags')
                                            ->label('Sumber Utama')
                                            ->multiple()
                                            ->closeOnSelect()
                                            ->searchable()
                                            ->preload()
                                            ->options(fn (): array => self::getTagOptions(TagType::Source))
                                            ->getOptionLabelUsing(fn (mixed $value): ?string => self::getTagLabel($value))
                                            ->getOptionLabelsUsing(fn (array $values): array => self::getTagLabels($values)),
                                        Select::make('issue_tags')
                                            ->label('Tema / Isu')
                                            ->multiple()
                                            ->closeOnSelect()
                                            ->searchable()
                                            ->preload()
                                            ->options(fn (): array => self::getTagOptions(TagType::Issue))
                                            ->getOptionLabelUsing(fn (mixed $value): ?string => self::getTagLabel($value))
                                            ->getOptionLabelsUsing(fn (array $values): array => self::getTagLabels($values))
                                            ->createOptionForm([
                                                TextInput::make('name')
                                                    ->label('Nama Tema')
                                                    ->required()
                                                    ->maxLength(255),
                                            ])
                                            ->createOptionUsing(fn (array $data): string => self::createPendingTag($data, TagType::Issue)),
                                    ])
                                    ->columns(2),
                                Section::make('Rujukan')
                                    ->columnSpanFull()
                                    ->schema([
                                        Select::make('references')
                                            ->label('Rujukan Kitab / Buku')
                                            ->relationship('references', 'title')
                                            ->getOptionLabelFromRecordUsing(fn (Reference $record): string => $record->displayTitle())
                                            ->multiple()
                                            ->closeOnSelect()
                                            ->searchable()
                                            ->preload()
                                            ->quickAdd(),
                                    ]),
                            ]),
                        Tab::make('Penganjur & Lokasi')
                            ->icon('heroicon-m-building-office')
                            ->schema([
                                Section::make('Penganjur')
                                    ->columnSpanFull()
                                    ->schema([
                                        Select::make('organizer_type')
                                            ->label('Jenis Penganjur')
                                            ->options([
                                                Institution::class => 'Institusi',
                                                Speaker::class => 'Penceramah',
                                            ])
                                            ->live()
                                            ->afterStateUpdated(function (Get $get, Set $set, ?Event $record): void {
                                                self::regenerateSlug($get, $set, $record);
                                            }),
                                        Select::make('organizer_id')
                                            ->label('Penganjur')
                                            ->searchable()
                                            ->preload()
                                            ->live()
                                            ->options(fn (Get $get): array => self::getOrganizerOptions($get('organizer_type')))
                                            ->required(fn (Get $get): bool => filled($get('organizer_type')))
                                            ->afterStateUpdated(function (Get $get, Set $set, ?Event $record): void {
                                                self::regenerateSlug($get, $set, $record);
                                            }),
                                        Select::make('series')
                                            ->label('Siri')
                                            ->relationship('series', 'title')
                                            ->multiple()
                                            ->searchable()
                                            ->preload(),
                                    ])
                                    ->columns(2),
                                Section::make('Lokasi')
                                    ->columnSpanFull()
                                    ->schema([
                                        Select::make('institution_id')
                                            ->label('Institusi')
                                            ->relationship('institution', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->live()
                                            ->disabled(fn (Get $get): bool => filled($get('venue_id')))
                                            ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                                if (filled($state)) {
                                                    $set('venue_id', null);
                                                }

                                                if (! filled($get('institution_id'))) {
                                                    $set('space_id', null);
                                                }
                                            })
                                            ->helperText('Pilih institusi ATAU lokasi (venue), bukan kedua-duanya.')
                                            ->createOptionForm(InstitutionFormSchema::createOptionForm())
                                            ->createOptionUsing(fn (array $data): string => InstitutionFormSchema::createOptionUsing($data)),
                                        Select::make('venue_id')
                                            ->label('Lokasi')
                                            ->relationship('venue', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->live()
                                            ->disabled(fn (Get $get): bool => filled($get('institution_id')))
                                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                                if (filled($state)) {
                                                    $set('institution_id', null);
                                                    $set('space_id', null);
                                                }
                                            })
                                            ->helperText('Pilih institusi ATAU lokasi (venue), bukan kedua-duanya.')
                                            ->createOptionForm(VenueFormSchema::createOptionForm())
                                            ->createOptionUsing(fn (array $data): string => VenueFormSchema::createOptionUsing($data)),
                                        Select::make('space_id')
                                            ->label('Ruang')
                                            ->relationship('space', 'name', function ($query, Get $get): mixed {
                                                $institutionId = $get('institution_id');

                                                if (! filled($institutionId)) {
                                                    return $query->where('is_active', true);
                                                }

                                                // Include institution-linked spaces and global spaces (not linked to any institution).
                                                return $query
                                                    ->where('is_active', true)
                                                    ->where(function ($spaceQuery) use ($institutionId): void {
                                                        $spaceQuery
                                                            ->whereHas('institutions', fn ($relatedQuery): mixed => $relatedQuery->where('institutions.id', $institutionId))
                                                            ->orWhereDoesntHave('institutions');
                                                    });
                                            })
                                            ->searchable()
                                            ->preload()
                                            ->visible(fn (Get $get): bool => filled($get('institution_id')) && blank($get('venue_id')))
                                            ->dehydrated(fn (Get $get): bool => filled($get('institution_id')) && blank($get('venue_id'))),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('Penceramah & Media')
                            ->icon('heroicon-m-user-group')
                            ->schema([
                                Section::make('Penceramah')
                                    ->columnSpanFull()
                                    ->schema([
                                        Select::make('speakers')
                                            ->label('Penceramah')
                                            ->required(fn (Get $get): bool => self::requiresSpeakersForEventTypes($get('event_type')))
                                            ->multiple()
                                            ->live()
                                            ->closeOnSelect()
                                            ->searchable()
                                            ->preload()
                                            ->afterStateUpdated(function (Get $get, Set $set, ?Event $record): void {
                                                self::regenerateSlug($get, $set, $record);
                                            })
                                            ->options(fn (): array => Speaker::query()
                                                ->whereIn('status', ['verified', 'pending'])
                                                ->orderBy('name')
                                                ->get()
                                                ->mapWithKeys(fn (Speaker $speaker): array => [(string) $speaker->id => $speaker->formatted_name])
                                                ->all())
                                            ->helperText(fn (Get $get): string => self::requiresSpeakersForEventTypes($get('event_type'))
                                                ? 'Sekurang-kurangnya seorang penceramah diperlukan untuk jenis majlis ini.'
                                                : 'Kosongkan jika majlis ini tidak mempunyai penceramah khusus.')
                                            ->getOptionLabelUsing(fn (mixed $value): ?string => Speaker::query()->find($value)?->formatted_name)
                                            ->getOptionLabelsUsing(fn (array $values): array => Speaker::query()
                                                ->whereIn('id', $values)
                                                ->get()
                                                ->mapWithKeys(fn (Speaker $speaker): array => [(string) $speaker->id => $speaker->formatted_name])
                                                ->toArray())
                                            ->createOptionForm(SpeakerFormSchema::createOptionForm())
                                            ->createOptionUsing(fn (array $data): string => SpeakerFormSchema::createOptionUsing($data)),
                                        Repeater::make('other_key_people')
                                            ->label('Peranan Lain')
                                            ->helperText('Tambahkan moderator, imam, khatib, bilal, atau PIC jika berkenaan.')
                                            ->schema([
                                                Select::make('role')
                                                    ->label('Peranan')
                                                    ->required()
                                                    ->options(EventKeyPersonRole::nonSpeakerOptions())
                                                    ->native(false),
                                                Select::make('speaker_id')
                                                    ->label('Pautkan Profil Penceramah')
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
                                                    ->getOptionLabelUsing(fn (mixed $value): ?string => Speaker::query()->find($value)?->formatted_name)
                                                    ->createOptionForm(SpeakerFormSchema::createOptionForm())
                                                    ->createOptionUsing(fn (array $data): string => SpeakerFormSchema::createOptionUsing($data)),
                                                TextInput::make('name')
                                                    ->label('Nama Paparan')
                                                    ->maxLength(255)
                                                    ->required(fn (Get $get): bool => blank($get('speaker_id')))
                                                    ->disabled(fn (Get $get): bool => filled($get('speaker_id')))
                                                    ->dehydrated(fn (Get $get): bool => blank($get('speaker_id'))),
                                                Toggle::make('is_public')
                                                    ->label('Papar Secara Awam')
                                                    ->default(true),
                                                Textarea::make('notes')
                                                    ->label('Nota Ringkas')
                                                    ->rows(2)
                                                    ->maxLength(500),
                                            ])
                                            ->default([])
                                            ->addActionLabel('Tambah Peranan')
                                            ->columns(2),
                                    ]),
                                Section::make('Media')
                                    ->columnSpanFull()
                                    ->schema([
                                        SpatieMediaLibraryFileUpload::make('cover')
                                            ->label('Gambar Cover Majlis')
                                            ->collection('cover')
                                            ->image()
                                            ->imageEditor()
                                            ->imageAspectRatio('16:9')
                                            ->automaticallyOpenImageEditorForAspectRatio()
                                            ->imageEditorAspectRatioOptions(['16:9', null])
                                            ->automaticallyCropImagesToAspectRatio()
                                            ->rules(['dimensions:ratio=16/9'])
                                            ->conversion('thumb')
                                            ->responsiveImages()
                                            ->helperText('Untuk paparan laman web dan aplikasi. Wajib 16:9, tanpa maklumat yang terlalu padat.'),
                                        SpatieMediaLibraryFileUpload::make('poster')
                                            ->label('Poster Hebahan')
                                            ->collection('poster')
                                            ->image()
                                            ->imageEditor()
                                            ->imageAspectRatio('4:5')
                                            ->automaticallyOpenImageEditorForAspectRatio()
                                            ->imageEditorAspectRatioOptions(['4:5', null])
                                            ->automaticallyCropImagesToAspectRatio()
                                            ->rules(['dimensions:ratio=4/5'])
                                            ->conversion('thumb')
                                            ->responsiveImages()
                                            ->helperText('Untuk hebahan WhatsApp, Instagram, Facebook, dan saluran luar. Wajib portrait 4:5 dan boleh mengandungi maklumat penuh.'),
                                        SpatieMediaLibraryFileUpload::make('gallery')
                                            ->label('Galeri')
                                            ->collection('gallery')
                                            ->multiple()
                                            ->reorderable()
                                            ->maxFiles(10)
                                            ->image()
                                            ->imageEditor()
                                            ->conversion('thumb')
                                            ->responsiveImages(),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('Semak & Moderasi')
                            ->icon('heroicon-m-shield-check')
                            ->schema([
                                Section::make('Moderasi')
                                    ->columnSpanFull()
                                    ->schema([
                                        Select::make('status')
                                            ->label('Status')
                                            ->options([
                                                'draft' => 'Draft',
                                                'pending' => 'Pending Review',
                                                'needs_changes' => 'Needs Changes',
                                                'approved' => 'Approved',
                                                'cancelled' => 'Cancelled',
                                                'rejected' => 'Rejected',
                                            ])
                                            ->disabled()
                                            ->dehydrated(false)
                                            ->helperText('Status boleh ditukar melalui butang moderasi di bahagian atas halaman.'),
                                        Toggle::make('is_priority')
                                            ->label('Priority Review')
                                            ->visible(fn (): bool => self::canManagePriorityFlag()),
                                        Toggle::make('is_featured')
                                            ->label('Featured Event')
                                            ->visible(fn (): bool => self::canManageFeaturedFlag()),
                                        Toggle::make('is_active')
                                            ->label('Active')
                                            ->helperText('Nyahaktifkan untuk menyembunyikan majlis daripada paparan awam tanpa menukar status.')
                                            ->default(true),
                                        DateTimePicker::make('published_at')
                                            ->label('Tarikh Terbit')
                                            ->disabled()
                                            ->dehydrated(false)
                                            ->helperText('Ditetapkan secara automatik apabila majlis diluluskan.'),
                                        DateTimePicker::make('escalated_at')
                                            ->label('Tarikh Eskalasi')
                                            ->visible(fn (): bool => self::canManageEscalationField()),
                                        Section::make('Submission')
                                            ->description('Rekod penghantaran asal dipaparkan di sini untuk rujukan sahaja dan tidak boleh diubah.')
                                            ->schema([
                                                Placeholder::make('submission_source')
                                                    ->label('Sumber')
                                                    ->content(fn (?Event $record): string => self::getSubmissionSourceLabel($record)),
                                                Placeholder::make('submission_recorded_at')
                                                    ->label('Dihantar Pada')
                                                    ->content(fn (?Event $record): string => self::getSubmissionRecordedAtLabel($record)),
                                                Placeholder::make('submission_submitter')
                                                    ->label('Penghantar')
                                                    ->columnSpanFull()
                                                    ->content(fn (?Event $record): HtmlString|string => self::getSubmissionSubmitterContent($record)),
                                                Placeholder::make('submission_notes')
                                                    ->label('Nota Penghantaran')
                                                    ->columnSpanFull()
                                                    ->content(fn (?Event $record): string => self::getSubmissionNotesLabel($record)),
                                            ])
                                            ->columns(2)
                                            ->visible(fn (?Event $record): bool => self::latestSubmission($record) instanceof EventSubmission),
                                        Toggle::make('registration_required')
                                            ->label('Pendaftaran Diperlukan')
                                            ->default(false)
                                            ->disabled(fn (?Event $record): bool => $record?->registrations()->exists() ?? false)
                                            ->helperText(fn (?Event $record): string => ($record?->registrations()->exists() ?? false)
                                                ? 'Tetapan ini dikunci selepas pendaftaran pertama.'
                                                : 'Aktifkan jika peserta perlu mendaftar sebelum hadir.'),
                                        Select::make('registration_mode')
                                            ->label('Mod Pendaftaran')
                                            ->options(
                                                collect(RegistrationMode::cases())
                                                    ->mapWithKeys(fn (RegistrationMode $mode): array => [$mode->value => $mode->label()])
                                                    ->toArray(),
                                            )
                                            ->disabled(fn (?Event $record): bool => $record?->registrations()->exists() ?? false)
                                            ->helperText(fn (?Event $record): ?string => ($record?->registrations()->exists() ?? false)
                                                ? __('Registration mode is locked after first registration.')
                                                : null)
                                            ->default(RegistrationMode::Event->value),
                                    ])
                                    ->columns(2),
                            ]),
                    ])
                    ->persistTabInQueryString(),
            ]);
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected static function getEventTypeOptions(): array
    {
        return collect(EventType::cases())
            ->mapToGroups(fn (EventType $type): array => [
                $type->getGroup() => [$type->value => $type->getLabel()],
            ])
            ->map(fn (Collection $group): array => $group->collapse()->toArray())
            ->toArray();
    }

    /**
     * @return array<int, string>
     */
    protected static function getLanguageOptions(): array
    {
        return Language::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    /**
     * @return array<string, string>
     */
    protected static function getTagOptions(TagType $type): array
    {
        return Tag::query()
            ->ofType($type)
            ->whereIn('status', ['verified', 'pending'])
            ->ordered()
            ->get()
            ->mapWithKeys(fn (Tag $tag): array => [
                (string) $tag->id => $tag->getTranslation('name', app()->getLocale()),
            ])
            ->toArray();
    }

    /**
     * @param  array{name: string}  $data
     */
    protected static function createPendingTag(array $data, TagType $type): string
    {
        $tag = Tag::create([
            'name' => ['ms' => $data['name'], 'en' => $data['name']],
            'type' => $type->value,
            'status' => 'pending',
        ]);

        return (string) $tag->getKey();
    }

    protected static function getTagLabel(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return Tag::query()->find($value)?->getTranslation('name', app()->getLocale());
    }

    /**
     * @param  array<int, string>  $values
     * @return array<string, string>
     */
    protected static function getTagLabels(array $values): array
    {
        return Tag::query()
            ->whereIn('id', $values)
            ->get()
            ->mapWithKeys(fn (Tag $tag): array => [
                (string) $tag->id => $tag->getTranslation('name', app()->getLocale()),
            ])
            ->toArray();
    }

    protected static function latestSubmission(?Event $record): ?EventSubmission
    {
        if (! $record instanceof Event) {
            return null;
        }

        $record->loadMissing([
            'submissions.contacts',
            'submissions.submitter',
        ]);

        /** @var EventSubmission|null $submission */
        $submission = $record->submissions
            ->sortByDesc(fn (EventSubmission $submission): int => $submission->created_at?->getTimestamp() ?? 0)
            ->first();

        return $submission;
    }

    protected static function getSubmissionSourceLabel(?Event $record): string
    {
        $submission = self::latestSubmission($record);

        if (! $submission instanceof EventSubmission) {
            return '-';
        }

        return $submission->submitter instanceof User
            ? 'Pengguna berdaftar'
            : 'Penghantaran awam';
    }

    protected static function getSubmissionRecordedAtLabel(?Event $record): string
    {
        $submission = self::latestSubmission($record);

        if (! $submission instanceof EventSubmission || ! $submission->created_at) {
            return '-';
        }

        $date = UserDateTimeFormatter::translatedFormat($submission->created_at, 'd M Y');
        $time = UserDateTimeFormatter::format($submission->created_at, 'h:i A');

        return trim($date.', '.$time, ', ');
    }

    protected static function getSubmissionSubmitterLabel(?Event $record): string
    {
        if (! $record instanceof Event) {
            return '-';
        }

        return SubmitterContactPresenter::labelForEvent($record);
    }

    protected static function getSubmissionSubmitterContent(?Event $record): HtmlString|string
    {
        $label = self::getSubmissionSubmitterLabel($record);

        if ($label === '-' || ! $record instanceof Event) {
            return $label;
        }

        $url = SubmitterContactPresenter::whatsappUrlForEvent($record);

        if ($url === null) {
            return $label;
        }

        return new HtmlString(sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline">%s</a>',
            e($url),
            e($label),
        ));
    }

    protected static function getSubmissionNotesLabel(?Event $record): string
    {
        $submission = self::latestSubmission($record);

        if (! $submission instanceof EventSubmission) {
            return '-';
        }

        return filled($submission->notes) ? (string) $submission->notes : '-';
    }

    /**
     * @return array<string, string>
     */
    protected static function getOrganizerOptions(?string $organizerType): array
    {
        return match ($organizerType) {
            Institution::class, 'institution' => Institution::query()
                ->whereIn('status', ['verified', 'pending'])
                ->orderBy('name')
                ->pluck('name', 'id')
                ->toArray(),
            Speaker::class, 'speaker' => Speaker::query()
                ->whereIn('status', ['verified', 'pending'])
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn (Speaker $speaker): array => [(string) $speaker->id => $speaker->formatted_name])
                ->toArray(),
            default => [],
        };
    }

    protected static function requiresSpeakersForEventTypes(mixed $eventTypes): bool
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

    private static function regenerateSlug(Get $get, Set $set, ?Event $record, ?string $title = null): void
    {
        $resolvedTitle = trim($title ?? (string) ($get('title') ?? ''));

        if ($resolvedTitle === '') {
            $set('slug', null);

            return;
        }

        $speakerSlugSegments = app(GenerateEventSlugAction::class)->speakerSlugSegmentsForSpeakerIds(
            is_array($get('speakers')) ? $get('speakers') : [],
        );

        if (
            $speakerSlugSegments === []
            && $get('organizer_type') === Speaker::class
            && filled($get('organizer_id'))
        ) {
            $speakerSlugSegments = app(GenerateEventSlugAction::class)->speakerSlugSegmentsForSpeakerIds([
                (string) $get('organizer_id'),
            ]);
        }

        $set('slug', app(GenerateEventSlugAction::class)->handle(
            $resolvedTitle,
            $get('event_date') ?? $get('starts_at') ?? null,
            is_string($get('timezone')) ? $get('timezone') : null,
            $record?->getKey() !== null ? (string) $record->getKey() : null,
            $speakerSlugSegments,
        ));
    }

    private static function canManageFeaturedFlag(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasApplicationAdminAccess()
            && Filament::getCurrentPanel()?->getId() === 'admin';
    }

    private static function canManagePriorityFlag(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && Filament::getCurrentPanel()?->getId() === 'admin';
    }

    private static function canManageEscalationField(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && Filament::getCurrentPanel()?->getId() === 'admin';
    }
}
