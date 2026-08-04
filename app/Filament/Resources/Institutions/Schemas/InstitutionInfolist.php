<?php

namespace App\Filament\Resources\Institutions\Schemas;

use AIArmada\Contacting\Enums\SocialPlatform;
use App\Models\Institution;
use App\Support\Location\AddressAssignments;
use App\Support\Location\AddressHierarchyFormatter;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class InstitutionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make('InstitutionViewTabs')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Profil')
                            ->icon('heroicon-m-building-library')
                            ->schema([
                                Section::make('Maklumat Asas')
                                    ->schema([
                                        SpatieMediaLibraryImageEntry::make('logo')
                                            ->label('Logo')
                                            ->collection('logo')
                                            ->conversion('thumb')
                                            ->circular()
                                            ->columnSpan(1),
                                        Grid::make(2)
                                            ->schema([
                                                TextEntry::make('name')
                                                    ->label('Nama Institusi'),
                                                TextEntry::make('display_name')
                                                    ->label('Nama Paparan'),
                                                TextEntry::make('slug')
                                                    ->label('Slug'),
                                                TextEntry::make('type')
                                                    ->label('Jenis Institusi')
                                                    ->badge(),
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
                                                TextEntry::make('description')
                                                    ->label('Penerangan')
                                                    ->columnSpanFull()
                                                    ->html()
                                                    ->placeholder('-'),
                                                TextEntry::make('languages.name')
                                                    ->label('Bahasa')
                                                    ->placeholder('-'),
                                                TextEntry::make('names.full_name')
                                                    ->label('Nama Alternatif')
                                                    ->listWithLineBreaks()
                                                    ->placeholder('-'),
                                            ]),
                                    ])
                                    ->columns(3),
                                Section::make('Imej')
                                    ->schema([
                                        SpatieMediaLibraryImageEntry::make('cover')
                                            ->label('Gambar Utama')
                                            ->collection('cover')
                                            ->conversion('banner'),
                                        SpatieMediaLibraryImageEntry::make('gallery')
                                            ->label('Galeri')
                                            ->collection('gallery')
                                            ->conversion('gallery_thumb')
                                            ->stacked()
                                            ->limit(6)
                                            ->limitedRemainingText(),
                                    ]),
                            ]),
                        Tab::make('Lokasi')
                            ->icon('heroicon-m-map-pin')
                            ->schema([
                                Section::make('Alamat')
                                    ->schema([
                                        TextEntry::make('address.line1')
                                            ->label('Alamat 1')
                                            ->state(fn (?Institution $record): ?string => $record?->primaryAddress()?->line1)
                                            ->placeholder('-'),
                                        TextEntry::make('address.line2')
                                            ->label('Alamat 2')
                                            ->state(fn (?Institution $record): ?string => $record?->primaryAddress()?->line2)
                                            ->placeholder('-'),
                                        TextEntry::make('address.postcode')
                                            ->label('Poskod')
                                            ->state(fn (?Institution $record): ?string => $record?->primaryAddress()?->postcode)
                                            ->placeholder('-'),
                                        TextEntry::make('address.city')
                                            ->label('Bandar / Kawasan')
                                            ->state(fn (?Institution $record): ?string => $record?->primaryAddress()?->city)
                                            ->placeholder('-'),
                                        TextEntry::make('address.district')
                                            ->label('Daerah')
                                            ->state(fn (?Institution $record): ?string => AddressHierarchyFormatter::roleAreaName($record?->primaryAddress(), AddressAssignments::ADMINISTRATIVE_DISTRICT))
                                            ->placeholder('-'),
                                        TextEntry::make('address.subdistrict')
                                            ->label('Mukim / Kawasan')
                                            ->state(fn (?Institution $record): ?string => AddressHierarchyFormatter::subdivisionOrLocalityName($record?->primaryAddress()))
                                            ->placeholder('-'),
                                        TextEntry::make('address.state')
                                            ->label('Negeri')
                                            ->state(fn (?Institution $record): ?string => $record?->primaryAddress()?->state)
                                            ->placeholder('-'),
                                        TextEntry::make('address.country')
                                            ->label('Negara')
                                            ->state(fn (?Institution $record): ?string => $record?->primaryAddress()?->country)
                                            ->placeholder('-'),
                                    ])
                                    ->columns(2),
                                Section::make('Koordinat')
                                    ->schema([
                                        TextEntry::make('address.latitude')
                                            ->label('Latitud')
                                            ->state(fn (?Institution $record): mixed => $record?->primaryAddress()?->latitude)
                                            ->placeholder('-'),
                                        TextEntry::make('address.longitude')
                                            ->label('Longitud')
                                            ->state(fn (?Institution $record): mixed => $record?->primaryAddress()?->longitude)
                                            ->placeholder('-'),
                                        TextEntry::make('address.google_maps_url')
                                            ->label('Pautan Google Maps')
                                            ->state(fn (?Institution $record): ?string => $record?->primaryAddress()?->google_maps_url)
                                            ->placeholder('-')
                                            ->url(fn (?string $state): ?string => filled($state) ? $state : null)
                                            ->openUrlInNewTab(),
                                        TextEntry::make('address.waze_url')
                                            ->label('Pautan Waze')
                                            ->state(fn (?Institution $record): ?string => $record?->primaryAddress()?->waze_url)
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
                                Section::make('Statistik Institusi')
                                    ->schema([
                                        TextEntry::make('events_count')
                                            ->label('Jumlah Majlis')
                                            ->state(function ($record): int {
                                                if (! array_key_exists('events_count', $record->getAttributes())) {
                                                    $record->loadCount(['events', 'members', 'persons', 'followers', 'reports']);
                                                }

                                                return (int) $record->getAttribute('events_count');
                                            })
                                            ->numeric(),
                                        TextEntry::make('members_count')
                                            ->label('Jumlah Ahli')
                                            ->state(fn ($record): int => (int) $record->getAttribute('members_count'))
                                            ->numeric(),
                                        TextEntry::make('persons_count')
                                            ->label('Jumlah Penceramah')
                                            ->state(fn ($record): int => (int) $record->getAttribute('persons_count'))
                                            ->numeric(),
                                        TextEntry::make('followers_count')
                                            ->label('Jumlah Pengikut')
                                            ->state(fn ($record): int => (int) $record->getAttribute('followers_count'))
                                            ->numeric(),
                                        TextEntry::make('reports_count')
                                            ->label('Jumlah Laporan')
                                            ->state(fn ($record): int => (int) $record->getAttribute('reports_count'))
                                            ->numeric(),
                                    ])
                                    ->columns(5),
                            ]),
                    ])
                    ->persistTabInQueryString(),
            ]);
    }
}
