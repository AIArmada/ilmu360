<?php

declare(strict_types=1);

namespace App\Features\Places\EntryPoints\Filament\Resources\Venues;

use AIArmada\FilamentEvents\Resources\VenueResource as PackageVenueResource;
use App\Features\Places\EntryPoints\Filament\Resources\Venues\Pages\ListVenues;
use App\Features\Places\EntryPoints\Filament\Resources\Venues\Pages\ViewVenue;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

final class VenueResource extends PackageVenueResource
{
    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListVenues::route('/'),
            'view' => ViewVenue::route('/{record}'),
        ];
    }
}
