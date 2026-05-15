<?php

declare(strict_types=1);

namespace App\Filament\Resources\References;

use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\References\Pages\CreateReference;
use App\Filament\Resources\References\Pages\EditReference;
use App\Filament\Resources\References\Pages\ListReferences;
use App\Filament\Resources\References\RelationManagers\EventsRelationManager;
use App\Filament\Resources\References\RelationManagers\MemberInvitationsRelationManager;
use App\Filament\Resources\References\RelationManagers\MembersRelationManager;
use App\Filament\Resources\References\Schemas\ReferenceForm;
use App\Filament\Resources\References\Tables\ReferencesTable;
use App\Models\Reference;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ReferenceResource extends Resource
{
    protected static ?string $model = Reference::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Directory';

    protected static ?string $recordTitleAttribute = 'title';

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return ReferenceForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return ReferencesTable::configure($table);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            EventsRelationManager::class,
            MembersRelationManager::class,
            MemberInvitationsRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListReferences::route('/'),
            'create' => CreateReference::route('/create'),
            'edit' => EditReference::route('/{record}/edit'),
        ];
    }
}
