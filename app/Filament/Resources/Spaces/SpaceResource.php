<?php

declare(strict_types=1);

namespace App\Filament\Resources\Spaces;

use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\Spaces\Pages\CreateSpace;
use App\Filament\Resources\Spaces\Pages\EditSpace;
use App\Filament\Resources\Spaces\Pages\ListSpaces;
use App\Filament\Resources\Spaces\Pages\ViewSpace;
use App\Filament\Resources\Spaces\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Spaces\RelationManagers\InstitutionsRelationManager;
use App\Filament\Resources\Spaces\Schemas\SpaceForm;
use App\Filament\Resources\Spaces\Schemas\SpaceInfolist;
use App\Filament\Resources\Spaces\Tables\SpacesTable;
use App\Models\Space;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class SpaceResource extends Resource
{
    protected static ?string $model = Space::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Directory';

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return SpaceForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return SpaceInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return SpacesTable::configure($table);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            InstitutionsRelationManager::class,
            EventsRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSpaces::route('/'),
            'create' => CreateSpace::route('/create'),
            'view' => ViewSpace::route('/{record}'),
            'edit' => EditSpace::route('/{record}/edit'),
        ];
    }
}
