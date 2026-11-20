<?php

declare(strict_types=1);

namespace App\Features\Places\EntryPoints\Filament\Resources\Spaces;

use AIArmada\FilamentEvents\Resources\VenueSpaceResource;
use App\Features\Events\Api\Filament\AdminEventsRelationManager;
use App\Features\Places\EntryPoints\Filament\Resources\Spaces\Pages\CreateSpace;
use App\Features\Places\EntryPoints\Filament\Resources\Spaces\Pages\EditSpace;
use App\Features\Places\EntryPoints\Filament\Resources\Spaces\Pages\ListSpaces;
use App\Features\Places\EntryPoints\Filament\Resources\Spaces\Pages\ViewSpace;
use App\Features\Places\EntryPoints\Filament\Resources\Spaces\RelationManagers\InstitutionsRelationManager;
use App\Features\Places\EntryPoints\Filament\Resources\Spaces\Schemas\SpaceForm;
use App\Features\Places\EntryPoints\Filament\Resources\Spaces\Schemas\SpaceInfolist;
use App\Features\Places\EntryPoints\Filament\Resources\Spaces\Tables\SpacesTable;
use App\Features\Places\Models\Space;
use App\Platform\Auditing\AuditsRelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SpaceResource extends VenueSpaceResource
{
    #[\Override]
    protected static ?string $model = Space::class;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return 'Directory';
    }

    /**
     * The standalone resource is the shared catalog; venue-owned spaces are managed from a venue.
     *
     * @return Builder<Space>
     */
    public static function getEloquentQuery(): Builder
    {
        return Space::query()->whereNull('venue_id');
    }

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
            app(AdminEventsRelationManager::class)->relationManager(),
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
