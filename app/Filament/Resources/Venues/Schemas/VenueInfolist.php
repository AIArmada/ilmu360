<?php

namespace App\Filament\Resources\Venues\Schemas;

use App\Enums\SocialMediaPlatform;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class VenueInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make('VenueViewTabs')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Profil')
                            ->icon('heroicon-m-map-pin')
                            ->schema([
                                Section::make('Maklumat Asas')
                                    ->schema([
                                        SpatieMediaLibraryImageEntry::make('cover')
                                            ->label('Imej Latar')
                                            ->collection('cover')
                                            ->conversion('thumb')
                                            ->columnSpan(1),
                                        Grid::make(2)
                                            ->schema([
                                                TextEntry::make('name')
                                                    ->label('Nama Venue'),
                                                TextEntry::make('slug')
                                                    ->label('Slug'),
                                                TextEntry::make('type')
                                                    ->label('Jenis Venue')
                                                    ->badge(),
                                                TextEntry::make('status')
                                                    ->label('Status')
                                                    ->badge()
                                                    ->color(fn (string $state): string => match ($state) {
                                                        'verified' => 'success',
                                                        'pending' => 'warning',
                                                        'rejected' => 'danger',
                                                        'unverified' => 'gray',
                                                        default => 'gray',
                                                    }),
                                                IconEntry::make('is_active')
                                                    ->label('Aktif')
                                                    ->boolean(),
                                                TextEntry::make('description')
                                                    ->label('Penerangan')
                                                    ->columnSpanFull()
                                                    ->placeholder('-'),
                                            ]),
                                    ])
                                    ->columns(3),
                                Section::make('Kemudahan')
                                    ->schema([
                                        TextEntry::make('facilities')
                                            ->label('')
                                            ->badge()
                                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                                'parking' => 'Parking',
                                                'oku' => 'OKU Access',
                                                'women_section' => 'Women Section',
                                                'ablution_area' => 'Ablution Area',
                                                default => $state,
                                            })
                                            ->placeholder('Tiada maklumat kemudahan'),
                                    ]),
                                Section::make('Imej')
                                    ->schema([
                                        SpatieMediaLibraryImageEntry::make('gallery')
                                            ->label('Galeri')
                                            ->collection('gallery')
                                            ->conversion('thumb')
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
                                            ->placeholder('-'),
                                        TextEntry::make('address.line2')
                                            ->label('Alamat 2')
                                            ->placeholder('-'),
                                        TextEntry::make('address.postcode')
                                            ->label('Poskod')
                                            ->placeholder('-'),
                                        TextEntry::make('address.city.name')
                                            ->label('Bandar')
                                            ->placeholder('-'),
                                        TextEntry::make('address.district.name')
                                            ->label('Daerah')
                                            ->placeholder('-'),
                                        TextEntry::make('address.state.name')
                                            ->label('Negeri')
                                            ->placeholder('-'),
                                        TextEntry::make('address.country.name')
                                            ->label('Negara')
                                            ->placeholder('-'),
                                    ])
                                    ->columns(2),
                                Section::make('Koordinat')
                                    ->schema([
                                        TextEntry::make('address.lat')
                                            ->label('Latitud')
                                            ->placeholder('-'),
                                        TextEntry::make('address.lng')
                                            ->label('Longitud')
                                            ->placeholder('-'),
                                        TextEntry::make('address.google_maps_url')
                                            ->label('Pautan Google Maps')
                                            ->placeholder('-')
                                            ->url(fn (?string $state): ?string => filled($state) ? $state : null)
                                            ->openUrlInNewTab(),
                                        TextEntry::make('address.waze_url')
                                            ->label('Pautan Waze')
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
                                        RepeatableEntry::make('contacts')
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
                                        RepeatableEntry::make('socialMedia')
                                            ->label('')
                                            ->schema([
                                                TextEntry::make('platform')
                                                    ->label('Platform')
                                                    ->formatStateUsing(function (mixed $state): string {
                                                        if ($state instanceof SocialMediaPlatform) {
                                                            return $state->getLabel();
                                                        }
                                                        if (is_string($state)) {
                                                            return SocialMediaPlatform::tryFrom($state)?->getLabel() ?? $state;
                                                        }

                                                        return '-';
                                                    })
                                                    ->badge(),
                                                TextEntry::make('username')
                                                    ->label('Username'),
                                                TextEntry::make('resolved_url')
                                                    ->label('URL')
                                                    ->url(fn (?string $state): ?string => filled($state) ? $state : null)
                                                    ->openUrlInNewTab(),
                                            ])
                                            ->columns(3)
                                            ->contained(false)
                                            ->placeholder('Tiada media sosial'),
                                    ]),
                            ]),
                        Tab::make('Statistik')
                            ->icon('heroicon-m-chart-bar')
                            ->schema([
                                Section::make('Statistik Venue')
                                    ->schema([
                                        TextEntry::make('events_count')
                                            ->label('Jumlah Majlis')
                                            ->state(fn ($record) => $record->events()->count())
                                            ->numeric(),
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
}
