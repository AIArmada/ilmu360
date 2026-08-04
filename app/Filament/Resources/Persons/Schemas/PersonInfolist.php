<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\Schemas;

use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\Persons\Enums\Gender;
use App\Enums\SpeakerStatus;
use App\Models\Person;
use App\Support\Location\AddressAssignments;
use App\Support\Location\AddressHierarchyFormatter;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class PersonInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make('PersonViewTabs')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Profil')
                            ->icon('heroicon-m-user')
                            ->schema([
                                Section::make('Maklumat Asas')
                                    ->schema([
                                        SpatieMediaLibraryImageEntry::make('avatar')
                                            ->label('Avatar')
                                            ->collection('avatar')
                                            ->conversion('thumb')
                                            ->circular()
                                            ->columnSpan(1),
                                        Grid::make(2)
                                            ->schema([
                                                TextEntry::make('name')
                                                    ->label('Nama'),
                                                TextEntry::make('formatted_name')
                                                    ->label('Nama Lengkap'),
                                                TextEntry::make('family_name')
                                                    ->label('Nama Keluarga')
                                                    ->placeholder('-'),
                                                TextEntry::make('middle_name')
                                                    ->label('Nama Pertengahan')
                                                    ->placeholder('-'),
                                                TextEntry::make('gender')
                                                    ->label('Jantina')
                                                    ->formatStateUsing(function (mixed $state): string {
                                                        if ($state instanceof Gender) {
                                                            return $state->label();
                                                        }
                                                        if (is_string($state)) {
                                                            return Gender::tryFrom($state)?->label() ?? $state;
                                                        }

                                                        return '-';
                                                    })
                                                    ->placeholder('-'),
                                                TextEntry::make('date_of_birth')
                                                    ->label('Tarikh Lahir')
                                                    ->date()
                                                    ->placeholder('-'),
                                                TextEntry::make('nationality.name')
                                                    ->label('Kewarganegaraan')
                                                    ->placeholder('-'),
                                                TextEntry::make('slug')
                                                    ->label('Slug'),
                                                TextEntry::make('status')
                                                    ->label('Status')
                                                    ->badge()
                                                    ->color(fn (string $state): string => match ($state) {
                                                        'pending' => 'warning',
                                                        'verified' => 'success',
                                                        'rejected' => 'danger',
                                                        'inactive' => 'gray',
                                                        default => 'gray',
                                                    }),
                                                TextEntry::make('speaker_status')
                                                    ->label('Status Penceramah')
                                                    ->badge()
                                                    ->placeholder('-')
                                                    ->formatStateUsing(function (mixed $state): string {
                                                        if ($state instanceof SpeakerStatus) {
                                                            return $state->getLabel();
                                                        }
                                                        if (is_string($state)) {
                                                            return SpeakerStatus::tryFrom($state)?->getLabel() ?? $state;
                                                        }

                                                        return '-';
                                                    }),
                                                TextEntry::make('bio')
                                                    ->label('Biografi')
                                                    ->columnSpanFull()
                                                    ->html()
                                                    ->state(function (?Person $record): string {
                                                        if (! $record instanceof Person || ! is_array($record->bio)) {
                                                            return '';
                                                        }

                                                        return RichContentRenderer::make($record->bio)->toHtml();
                                                    })
                                                    ->placeholder('-'),
                                                TextEntry::make('languages.name')
                                                    ->label('Bahasa')
                                                    ->placeholder('-'),
                                                TextEntry::make('names.full_name')
                                                    ->label('Nama Alternatif')
                                                    ->listWithLineBreaks()
                                                    ->placeholder('-'),
                                                TextEntry::make('titles_summary')
                                                    ->label('Gelaran')
                                                    ->state(function (?Person $record): string {
                                                        if (! $record instanceof Person) {
                                                            return '-';
                                                        }

                                                        $titles = $record->relationLoaded('titleAssignments')
                                                            ? $record->titleAssignments
                                                            : $record->titleAssignments()->with('title')->get();

                                                        return $titles->pluck('title.name')->implode(', ') ?: '-';
                                                    }),
                                            ]),
                                    ])
                                    ->columns(3),
                                Section::make('Imej')
                                    ->schema([
                                        SpatieMediaLibraryImageEntry::make('main')
                                            ->label('Gambar Utama')
                                            ->collection('main')
                                            ->conversion('thumb'),
                                        SpatieMediaLibraryImageEntry::make('profile')
                                            ->label('Gambar Profil')
                                            ->collection('profile')
                                            ->conversion('profile_thumb'),
                                        SpatieMediaLibraryImageEntry::make('cover')
                                            ->label('Gambar Sampul')
                                            ->collection('cover')
                                            ->conversion('banner'),
                                        SpatieMediaLibraryImageEntry::make('gallery')
                                            ->label('Galeri')
                                            ->collection('gallery')
                                            ->conversion('gallery_thumb')
                                            ->stacked()
                                            ->limit(6)
                                            ->limitedRemainingText(),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('Lokasi')
                            ->icon('heroicon-m-map-pin')
                            ->schema([
                                Section::make('Alamat')
                                    ->schema([
                                        TextEntry::make('address.line1')
                                            ->label('Alamat 1')
                                            ->state(fn (?Person $record): ?string => $record?->primaryAddress()?->line1)
                                            ->placeholder('-'),
                                        TextEntry::make('address.line2')
                                            ->label('Alamat 2')
                                            ->state(fn (?Person $record): ?string => $record?->primaryAddress()?->line2)
                                            ->placeholder('-'),
                                        TextEntry::make('address.postcode')
                                            ->label('Poskod')
                                            ->state(fn (?Person $record): ?string => $record?->primaryAddress()?->postcode)
                                            ->placeholder('-'),
                                        TextEntry::make('address.city')
                                            ->label('Bandar / Kawasan')
                                            ->state(fn (?Person $record): ?string => $record?->primaryAddress()?->city)
                                            ->placeholder('-'),
                                        TextEntry::make('address.district')
                                            ->label('Daerah')
                                            ->state(fn (?Person $record): ?string => AddressHierarchyFormatter::roleAreaName($record?->primaryAddress(), AddressAssignments::ADMINISTRATIVE_DISTRICT))
                                            ->placeholder('-'),
                                        TextEntry::make('address.subdistrict')
                                            ->label('Mukim / Kawasan')
                                            ->state(fn (?Person $record): ?string => AddressHierarchyFormatter::subdivisionOrLocalityName($record?->primaryAddress()))
                                            ->placeholder('-'),
                                        TextEntry::make('address.state')
                                            ->label('Negeri')
                                            ->state(fn (?Person $record): ?string => $record?->primaryAddress()?->state)
                                            ->placeholder('-'),
                                        TextEntry::make('address.country')
                                            ->label('Negara')
                                            ->state(fn (?Person $record): ?string => $record?->primaryAddress()?->country)
                                            ->placeholder('-'),
                                    ])
                                    ->columns(2),
                                Section::make('Koordinat')
                                    ->schema([
                                        TextEntry::make('address.latitude')
                                            ->label('Latitud')
                                            ->state(fn (?Person $record): mixed => $record?->primaryAddress()?->latitude)
                                            ->placeholder('-'),
                                        TextEntry::make('address.longitude')
                                            ->label('Longitud')
                                            ->state(fn (?Person $record): mixed => $record?->primaryAddress()?->longitude)
                                            ->placeholder('-'),
                                        TextEntry::make('address.google_maps_url')
                                            ->label('Pautan Google Maps')
                                            ->state(fn (?Person $record): ?string => $record?->primaryAddress()?->google_maps_url)
                                            ->placeholder('-')
                                            ->url(fn (?string $state): ?string => filled($state) ? $state : null)
                                            ->openUrlInNewTab(),
                                        TextEntry::make('address.waze_url')
                                            ->label('Pautan Waze')
                                            ->state(fn (?Person $record): ?string => $record?->primaryAddress()?->waze_url)
                                            ->placeholder('-')
                                            ->url(fn (?string $state): ?string => filled($state) ? $state : null)
                                            ->openUrlInNewTab(),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('Hubungan')
                            ->icon('heroicon-m-phone')
                            ->schema([
                                Section::make('Hubungi')
                                    ->schema([
                                        RepeatableEntry::make('contactMethods')
                                            ->label('')
                                            ->schema([
                                                TextEntry::make('category')
                                                    ->label('Kategori')
                                                    ->badge(),
                                                TextEntry::make('value')
                                                    ->label('Nilai'),
                                                TextEntry::make('type')
                                                    ->label('Jenis')
                                                    ->badge(),
                                                IconEntry::make('is_public')
                                                    ->label('Paparan Awam')
                                                    ->boolean(),
                                            ])
                                            ->columns(4)
                                            ->contained(false)
                                            ->placeholder('Tiada maklumat hubungan'),
                                    ]),
                            ]),
                        Tab::make('Media Sosial')
                            ->icon('heroicon-m-share')
                            ->schema([
                                Section::make('Media Sosial')
                                    ->schema([
                                        RepeatableEntry::make('socialProfiles')
                                            ->label('')
                                            ->schema([
                                                TextEntry::make('platform')
                                                    ->label('Platform')
                                                    ->formatStateUsing(function (mixed $state): string {
                                                        if ($state instanceof SocialPlatform) {
                                                            return $state->label();
                                                        }
                                                        if (is_string($state)) {
                                                            return SocialPlatform::tryFrom($state)?->label() ?? $state;
                                                        }

                                                        return '-';
                                                    })
                                                    ->badge(),
                                                TextEntry::make('handle')
                                                    ->label('Handle'),
                                                TextEntry::make('url')
                                                    ->label('URL')
                                                    ->url(fn (?string $state): ?string => filled($state) ? $state : null)
                                                    ->openUrlInNewTab(),
                                            ])
                                            ->columns(3)
                                            ->contained(false)
                                            ->placeholder('Tiada media sosial'),
                                    ]),
                            ]),
                        Tab::make('Status')
                            ->icon('heroicon-m-shield-check')
                            ->schema([
                                Section::make('Status & Kelulusan')
                                    ->schema([
                                        TextEntry::make('status')
                                            ->label('Status')
                                            ->badge()
                                            ->color(fn (string $state): string => match ($state) {
                                                'pending' => 'warning',
                                                'verified' => 'success',
                                                'rejected' => 'danger',
                                                'inactive' => 'gray',
                                                default => 'gray',
                                            }),
                                        TextEntry::make('speaker_status')
                                            ->label('Status Penceramah')
                                            ->badge()
                                            ->placeholder('-')
                                            ->formatStateUsing(function (mixed $state): string {
                                                if ($state instanceof SpeakerStatus) {
                                                    return $state->getLabel();
                                                }
                                                if (is_string($state)) {
                                                    return SpeakerStatus::tryFrom($state)?->getLabel() ?? $state;
                                                }

                                                return '-';
                                            }),
                                        IconEntry::make('allow_public_event_submission')
                                            ->label('Terima Penghantaran Majlis Awam')
                                            ->boolean(),
                                        TextEntry::make('verified_at')
                                            ->label('Disahkan Pada')
                                            ->dateTime()
                                            ->placeholder('-'),
                                        TextEntry::make('verifier.name')
                                            ->label('Disahkan Oleh')
                                            ->placeholder('-'),
                                        TextEntry::make('rejected_at')
                                            ->label('Ditolak Pada')
                                            ->dateTime()
                                            ->placeholder('-'),
                                        TextEntry::make('last_state_change_at')
                                            ->label('Perubahan Status Terakhir')
                                            ->dateTime()
                                            ->placeholder('-'),
                                        TextEntry::make('published_at')
                                            ->label('Diterbitkan Pada')
                                            ->dateTime()
                                            ->placeholder('-'),
                                        TextEntry::make('public_submission_locked_at')
                                            ->label('Penghantaran Awam Dikunci Pada')
                                            ->dateTime()
                                            ->placeholder('-'),
                                        TextEntry::make('created_at')
                                            ->label('Dicipta Pada')
                                            ->dateTime(),
                                        TextEntry::make('updated_at')
                                            ->label('Dikemas Kini Pada')
                                            ->dateTime(),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('Statistik')
                            ->icon('heroicon-m-chart-bar')
                            ->schema([
                                Section::make('Statistik Person')
                                    ->schema([
                                        TextEntry::make('events_count')
                                            ->label('Jumlah Majlis')
                                            ->state(function (Person $record): int {
                                                if (! array_key_exists('events_count', $record->getAttributes())) {
                                                    $record->loadCount(['events', 'followers', 'members', 'reports']);
                                                }

                                                return (int) $record->getAttribute('events_count');
                                            })
                                            ->numeric(),
                                        TextEntry::make('followers_count')
                                            ->label('Jumlah Pengikut')
                                            ->state(fn (Person $record): int => (int) $record->getAttribute('followers_count'))
                                            ->numeric(),
                                        TextEntry::make('members_count')
                                            ->label('Jumlah Ahli')
                                            ->state(fn (Person $record): int => (int) $record->getAttribute('members_count'))
                                            ->numeric(),
                                        TextEntry::make('reports_count')
                                            ->label('Jumlah Laporan')
                                            ->state(fn (Person $record): int => (int) $record->getAttribute('reports_count'))
                                            ->numeric(),
                                    ])
                                    ->columns(4),
                            ]),
                    ])
                    ->persistTabInQueryString(),
            ]);
    }
}
