<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Enums\TagType;
use App\Enums\TimingMode;
use App\Filament\Ahli\Resources\Institutions\InstitutionResource as AhliInstitutionResource;
use App\Filament\Resources\Institutions\InstitutionResource as AdminInstitutionResource;
use App\Filament\Resources\References\ReferenceResource as AdminReferenceResource;
use App\Filament\Resources\Series\SeriesResource as AdminSeriesResource;
use App\Filament\Resources\Speakers\SpeakerResource as AdminSpeakerResource;
use App\Filament\Resources\Venues\VenueResource as AdminVenueResource;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Series;
use App\Models\Speaker;
use App\Support\Events\SubmitterContactPresenter;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

class EventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make('EventViewTabs')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Maklumat Majlis')
                            ->icon('heroicon-m-document-text')
                            ->schema([
                                Section::make('Maklumat Utama')
                                    ->schema([
                                        TextEntry::make('title')
                                            ->label('Tajuk Majlis'),
                                        TextEntry::make('slug')
                                            ->label('Slug'),
                                        TextEntry::make('description')
                                            ->label('Keterangan')
                                            ->state(fn (Event $record): string => self::normalizeDescription($record->description))
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2),
                                Section::make('Waktu & Format')
                                    ->schema([
                                        TextEntry::make('timing_mode')
                                            ->label('Mode Waktu')
                                            ->formatStateUsing(fn (mixed $state): string => self::formatEnumValue($state))
                                            ->badge(),
                                        TextEntry::make('prayer_reference')
                                            ->label('Waktu Solat')
                                            ->formatStateUsing(fn (mixed $state): string => self::formatEnumValue($state))
                                            ->placeholder('-'),
                                        TextEntry::make('prayer_offset')
                                            ->label('Offset')
                                            ->formatStateUsing(fn (mixed $state): string => self::formatEnumValue($state))
                                            ->placeholder('-'),
                                        TextEntry::make('prayer_display_text')
                                            ->label('Paparan Waktu')
                                            ->placeholder('-'),
                                        TextEntry::make('starts_at')
                                            ->label('Waktu Mula')
                                            ->dateTime(format: fn (Event $record): string => self::usesPrayerRelativeTiming($record) ? 'M d, Y' : 'M d, Y H:i:s'),
                                        TextEntry::make('ends_at')
                                            ->label('Waktu Tamat')
                                            ->dateTime()
                                            ->placeholder('-'),
                                        TextEntry::make('timezone')
                                            ->label('Timezone')
                                            ->placeholder('-'),
                                        TextEntry::make('event_format')
                                            ->label('Format Majlis')
                                            ->formatStateUsing(fn (mixed $state): string => self::formatEnumValue($state))
                                            ->badge(),
                                        TextEntry::make('visibility')
                                            ->label('Keterlihatan')
                                            ->formatStateUsing(fn (mixed $state): string => self::formatEnumValue($state))
                                            ->badge(),
                                        TextEntry::make('event_url')
                                            ->label('Pautan Majlis')
                                            ->placeholder('-')
                                            ->url(fn (?string $state): ?string => filled($state) ? $state : null)
                                            ->openUrlInNewTab(),
                                        TextEntry::make('live_url')
                                            ->label('Pautan Siaran Langsung')
                                            ->placeholder('-')
                                            ->url(fn (?string $state): ?string => filled($state) ? $state : null)
                                            ->openUrlInNewTab(),
                                        TextEntry::make('recording_url')
                                            ->label('Pautan Rakaman')
                                            ->placeholder('-')
                                            ->url(fn (?string $state): ?string => filled($state) ? $state : null)
                                            ->openUrlInNewTab(),
                                    ])
                                    ->columns(2),
                                Section::make('Sasaran Peserta')
                                    ->schema([
                                        TextEntry::make('gender')
                                            ->label('Jantina')
                                            ->formatStateUsing(fn (mixed $state): string => self::formatEnumValue($state))
                                            ->badge(),
                                        TextEntry::make('age_group')
                                            ->label('Peringkat Umur')
                                            ->formatStateUsing(fn (mixed $state): string => self::formatEnumCollection($state))
                                            ->placeholder('-'),
                                        IconEntry::make('children_allowed')
                                            ->label('Kanak-kanak Dibenarkan')
                                            ->boolean(),
                                        IconEntry::make('is_muslim_only')
                                            ->label('Terbuka untuk Muslim Sahaja')
                                            ->boolean(),
                                        TextEntry::make('languages.name')
                                            ->label('Bahasa')
                                            ->listWithLineBreaks()
                                            ->placeholder('-')
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('Kategori & Bidang')
                            ->icon('heroicon-m-tag')
                            ->schema([
                                Section::make('Kategori')
                                    ->schema([
                                        TextEntry::make('event_type')
                                            ->label('Jenis Majlis')
                                            ->formatStateUsing(fn (mixed $state): string => self::formatEnumCollection($state))
                                            ->placeholder('-')
                                            ->columnSpanFull(),
                                        TextEntry::make('domain_tags')
                                            ->label('Kategori')
                                            ->state(fn (Event $record): string => self::formatTagList($record, TagType::Domain))
                                            ->placeholder('-'),
                                        TextEntry::make('discipline_tags')
                                            ->label('Bidang Ilmu')
                                            ->state(fn (Event $record): string => self::formatTagList($record, TagType::Discipline))
                                            ->placeholder('-'),
                                        TextEntry::make('source_tags')
                                            ->label('Sumber Utama')
                                            ->state(fn (Event $record): string => self::formatTagList($record, TagType::Source))
                                            ->placeholder('-'),
                                        TextEntry::make('issue_tags')
                                            ->label('Tema / Isu')
                                            ->state(fn (Event $record): string => self::formatTagList($record, TagType::Issue))
                                            ->placeholder('-'),
                                        RepeatableEntry::make('references')
                                            ->label('Rujukan Kitab / Buku')
                                            ->schema([
                                                TextEntry::make('title')
                                                    ->hiddenLabel()
                                                    ->url(fn (?Reference $record): ?string => self::resourceEditUrl($record?->id, AdminReferenceResource::class)),
                                            ])
                                            ->contained(false)
                                            ->placeholder('-')
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('Penganjur & Lokasi')
                            ->icon('heroicon-m-building-office')
                            ->schema([
                                Section::make('Penganjur')
                                    ->schema([
                                        TextEntry::make('organizer_type')
                                            ->label('Jenis Penganjur')
                                            ->formatStateUsing(fn (?string $state): string => self::formatOrganizerType($state))
                                            ->placeholder('-'),
                                        TextEntry::make('organizer.name')
                                            ->label('Penganjur')
                                            ->placeholder('-')
                                            ->url(function (Event $record): ?string {
                                                if (! $record->organizer) {
                                                    return null;
                                                }

                                                if ($record->organizer_type === Institution::class) {
                                                    return self::resourceEditUrl($record->organizer_id, AdminInstitutionResource::class, AhliInstitutionResource::class);
                                                }

                                                if ($record->organizer_type === Speaker::class) {
                                                    return self::resourceEditUrl($record->organizer_id, AdminSpeakerResource::class);
                                                }

                                                return null;
                                            }),
                                        RepeatableEntry::make('series')
                                            ->label('Siri')
                                            ->schema([
                                                TextEntry::make('title')
                                                    ->hiddenLabel()
                                                    ->url(fn (?Series $record): ?string => self::resourceEditUrl($record?->id, AdminSeriesResource::class)),
                                            ])
                                            ->contained(false)
                                            ->placeholder('-')
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2),
                                Section::make('Lokasi')
                                    ->schema([
                                        TextEntry::make('institution.name')
                                            ->label('Institusi')
                                            ->placeholder('-')
                                            ->url(fn (Event $record): ?string => self::resourceEditUrl($record->institution_id, AdminInstitutionResource::class, AhliInstitutionResource::class)),
                                        TextEntry::make('venue.name')
                                            ->label('Lokasi')
                                            ->placeholder('-')
                                            ->url(fn (Event $record): ?string => self::resourceEditUrl($record->venue_id, AdminVenueResource::class)),
                                        TextEntry::make('space.name')
                                            ->label('Ruang')
                                            ->placeholder('-'),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('Penceramah & Media')
                            ->icon('heroicon-m-user-group')
                            ->schema([
                                Section::make('Penceramah')
                                    ->schema([
                                        RepeatableEntry::make('speakers')
                                            ->label('Penceramah')
                                            ->schema([
                                                TextEntry::make('name')
                                                    ->hiddenLabel()
                                                    ->url(fn (?Speaker $record): ?string => self::resourceEditUrl($record?->id, AdminSpeakerResource::class)),
                                            ])
                                            ->contained(false)
                                            ->placeholder('-'),
                                        RepeatableEntry::make('nonSpeakerKeyPeople')
                                            ->label('Peranan Lain')
                                            ->schema([
                                                TextEntry::make('role')
                                                    ->label('Peranan')
                                                    ->formatStateUsing(fn (mixed $state): string => self::formatEnumValue($state)),
                                                TextEntry::make('display_name')
                                                    ->label('Nama')
                                                    ->state(fn ($record): string => $record->display_name),
                                            ])
                                            ->contained(false)
                                            ->placeholder('-'),
                                    ]),
                                Section::make('Media')
                                    ->schema([
                                        SpatieMediaLibraryImageEntry::make('cover')
                                            ->label('Gambar Cover Majlis')
                                            ->collection('cover')
                                            ->conversion('preview')
                                            ->columnSpanFull(),
                                        SpatieMediaLibraryImageEntry::make('poster')
                                            ->label('Poster Hebahan')
                                            ->collection('poster')
                                            ->conversion('preview')
                                            ->columnSpanFull(),
                                        SpatieMediaLibraryImageEntry::make('gallery')
                                            ->label('Galeri')
                                            ->collection('gallery')
                                            ->conversion('thumb')
                                            ->stacked()
                                            ->limit(6)
                                            ->limitedRemainingText(),
                                    ])
                                    ->columns(1),
                            ]),
                        Tab::make('Semak & Moderasi')
                            ->icon('heroicon-m-shield-check')
                            ->schema([
                                Section::make('Moderasi')
                                    ->schema([
                                        TextEntry::make('status')
                                            ->label('Status')
                                            ->badge()
                                            ->color(fn (string $state): string => match ($state) {
                                                'pending' => 'warning',
                                                'approved' => 'success',
                                                'needs_changes' => 'info',
                                                'cancelled' => 'danger',
                                                'rejected' => 'danger',
                                                default => 'gray',
                                            }),
                                        TextEntry::make('settings.registration_mode')
                                            ->label('Mod Pendaftaran')
                                            ->formatStateUsing(fn (mixed $state): string => self::formatEnumValue($state))
                                            ->badge()
                                            ->placeholder('-'),
                                        IconEntry::make('is_priority')
                                            ->label('Priority Review')
                                            ->boolean(),
                                        IconEntry::make('is_featured')
                                            ->label('Featured Event')
                                            ->boolean(),
                                        IconEntry::make('is_active')
                                            ->label('Active')
                                            ->boolean(),
                                        TextEntry::make('published_at')
                                            ->label('Tarikh Terbit')
                                            ->dateTime()
                                            ->placeholder('-'),
                                        TextEntry::make('escalated_at')
                                            ->label('Tarikh Eskalasi')
                                            ->dateTime()
                                            ->placeholder('-'),
                                        TextEntry::make('submitter_info')
                                            ->label('Penghantar')
                                            ->state(fn (Event $record): string => SubmitterContactPresenter::labelForEvent($record))
                                            ->url(fn (Event $record): ?string => SubmitterContactPresenter::whatsappUrlForEvent($record))
                                            ->openUrlInNewTab()
                                            ->columnSpanFull(),
                                        TextEntry::make('created_at')
                                            ->label('Dicipta Pada')
                                            ->dateTime(),
                                        TextEntry::make('updated_at')
                                            ->label('Dikemas Kini Pada')
                                            ->dateTime(),
                                    ])
                                    ->columns(2),
                            ]),
                    ])
                    ->persistTabInQueryString(),
            ]);
    }

    protected static function normalizeDescription(mixed $description): string
    {
        if (is_string($description) && filled($description)) {
            return self::stripDescriptionHtml($description);
        }

        if (is_array($description)) {
            $html = data_get($description, 'html');

            if (is_string($html) && filled($html)) {
                return self::stripDescriptionHtml($html);
            }

            $content = data_get($description, 'content');

            if (is_string($content) && filled($content)) {
                return $content;
            }

            $flattened = collect($description)
                ->flatten()
                ->filter(fn (mixed $value): bool => is_string($value) && filled($value))
                ->implode(' ');

            if ($flattened !== '') {
                return $flattened;
            }
        }

        return '-';
    }

    protected static function stripDescriptionHtml(string $description): string
    {
        $stripped = trim(strip_tags($description));

        return $stripped !== '' ? $stripped : '-';
    }

    protected static function formatTagList(Event $event, TagType $type): string
    {
        $tags = $event->tags
            ->where('type', $type->value)
            ->pluck('name')
            ->map(fn (mixed $name): string => self::normalizeTagName($name))
            ->filter()
            ->values();

        if ($tags->isEmpty()) {
            return '-';
        }

        return $tags->implode(', ');
    }

    protected static function normalizeTagName(mixed $name): string
    {
        if (is_string($name)) {
            return $name;
        }

        if (is_array($name)) {
            $locale = app()->getLocale();

            return (string) ($name[$locale] ?? $name['en'] ?? collect($name)->first() ?? '');
        }

        return '';
    }

    protected static function formatOrganizerType(?string $state): string
    {
        return match ($state) {
            Institution::class, 'institution' => 'Institusi',
            Speaker::class, 'speaker' => 'Penceramah',
            default => '-',
        };
    }

    protected static function usesPrayerRelativeTiming(Event $record): bool
    {
        $timingMode = $record->timing_mode;

        if ($timingMode instanceof TimingMode) {
            return $timingMode === TimingMode::PrayerRelative;
        }

        return $timingMode === TimingMode::PrayerRelative->value;
    }

    protected static function formatEnumCollection(mixed $state): string
    {
        $items = $state instanceof Collection
            ? $state->all()
            : (is_array($state) ? $state : [$state]);

        return collect($items)
            ->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value): string => self::formatEnumValue($value))
            ->filter()
            ->implode(', ');
    }

    protected static function formatEnumValue(mixed $state): string
    {
        if ($state instanceof BackedEnum) {
            if (method_exists($state, 'getLabel')) {
                return (string) $state->getLabel();
            }

            if (method_exists($state, 'label')) {
                return (string) $state->label();
            }

            return (string) $state->value;
        }

        return is_scalar($state) ? (string) $state : '';
    }

    protected static function resourceEditUrl(
        int|string|null $record,
        string $adminResourceClass,
        ?string $ahliResourceClass = null,
    ): ?string {
        if (! filled($record)) {
            return null;
        }

        $panel = Filament::getCurrentPanel();

        if ($panel?->getId() === 'ahli') {
            if ($ahliResourceClass === null) {
                return null;
            }

            return self::resourceUrlForPanel($ahliResourceClass, 'edit', $record, $panel);
        }

        return self::resourceUrlForPanel(
            $adminResourceClass,
            'edit',
            $record,
            $panel ?? Filament::getCurrentOrDefaultPanel(),
        );
    }

    protected static function resourceUrlForPanel(
        string $resourceClass,
        string $action,
        int|string $record,
        ?Panel $panel,
    ): ?string {
        if (! $panel instanceof Panel) {
            return null;
        }

        $routeName = $resourceClass::getRouteBaseName($panel).'.'.$action;

        if (! Route::has($routeName)) {
            return null;
        }

        return $resourceClass::getUrl($action, ['record' => $record], panel: $panel->getId());
    }
}
