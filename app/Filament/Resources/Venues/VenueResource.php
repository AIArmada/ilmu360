<?php

declare(strict_types=1);

namespace App\Filament\Resources\Venues;

use AIArmada\FilamentEvents\Resources\VenueResource as PackageVenueResource;
use App\Filament\Resources\Venues\Pages\ListVenues;
use App\Filament\Resources\Venues\Pages\ViewVenue;
use App\Filament\Resources\Venues\RelationManagers\SpacesRelationManager;
use App\Models\Venue;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

final class VenueResource extends Resource
{
    protected static ?string $model = Venue::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return 'Directory';
    }

    public static function table(Table $table): Table
    {
        return PackageVenueResource::table($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PackageVenueResource::infolist($schema);
    }

    public static function getRelations(): array
    {
        return [SpacesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVenues::route('/'),
            'view' => ViewVenue::route('/{record}'),
        ];
    }
}
