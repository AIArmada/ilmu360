<?php

declare(strict_types=1);

namespace App\Filament\Resources\CommunityReleases;

use App\Filament\Resources\CommunityReleases\Pages\ListCommunityReleases;
use App\Filament\Resources\CommunityReleases\Pages\ViewCommunityRelease;
use App\Filament\Resources\CommunityReleases\Schemas\CommunityReleaseInfolist;
use App\Filament\Resources\CommunityReleases\Tables\CommunityReleasesTable;
use App\Models\CommunityRelease;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CommunityReleaseResource extends Resource
{
    protected static ?string $model = CommunityRelease::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Insights';

    protected static ?string $recordTitleAttribute = 'release_key';

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return CommunityReleaseInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return CommunityReleasesTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListCommunityReleases::route('/'),
            'view' => ViewCommunityRelease::route('/{record}'),
        ];
    }
}
