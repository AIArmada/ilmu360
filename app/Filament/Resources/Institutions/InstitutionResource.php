<?php

declare(strict_types=1);

namespace App\Filament\Resources\Institutions;

use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\Institutions\Pages\CreateInstitution;
use App\Filament\Resources\Institutions\Pages\EditInstitution;
use App\Filament\Resources\Institutions\Pages\ListInstitutions;
use App\Filament\Resources\Institutions\Pages\ViewInstitution;
use App\Filament\Resources\Institutions\RelationManagers\DonationChannelsRelationManager;
use App\Filament\Resources\Institutions\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Institutions\RelationManagers\MemberInvitationsRelationManager;
use App\Filament\Resources\Institutions\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Institutions\Schemas\InstitutionForm;
use App\Filament\Resources\Institutions\Schemas\InstitutionInfolist;
use App\Filament\Resources\Institutions\Tables\InstitutionsTable;
use App\Models\Institution;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class InstitutionResource extends Resource
{
    protected static ?string $model = Institution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Directory';

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return InstitutionForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return InstitutionInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return InstitutionsTable::configure($table);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            EventsRelationManager::class,
            DonationChannelsRelationManager::class,
            MembersRelationManager::class,
            MemberInvitationsRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListInstitutions::route('/'),
            'create' => CreateInstitution::route('/create'),
            'view' => ViewInstitution::route('/{record}'),
            'edit' => EditInstitution::route('/{record}/edit'),
        ];
    }
}
