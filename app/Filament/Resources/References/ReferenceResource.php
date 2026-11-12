<?php

declare(strict_types=1);

namespace App\Filament\Resources\References;

use AIArmada\FilamentEvents\RelationManagers\EventsRelationManager;
use App\Filament\Resources\References\Pages\CreateReference;
use App\Filament\Resources\References\Pages\EditReference;
use App\Filament\Resources\References\Pages\ListReferences;
use App\Filament\Resources\References\RelationManagers\MemberInvitationsRelationManager;
use App\Filament\Resources\References\RelationManagers\MembersRelationManager;
use App\Filament\Resources\References\Schemas\ReferenceForm;
use App\Filament\Resources\References\Tables\ReferencesTable;
use App\Models\Reference;
use App\Platform\Auditing\AuditsRelationManager;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ReferenceResource extends Resource
{
    #[\Override]
    protected static ?string $model = Reference::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'Directory';

    #[\Override]
    protected static ?string $recordTitleAttribute = 'title';

    /**
     * References are global records; do not require ambient owner context when
     * Filament resolves records before the page boots.
     *
     * @return Builder<Reference>
     */
    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        // The admin panel has no tenancy; querying the model directly keeps
        // the owner-scope call statically typed. Revisit if panel tenancy is
        // enabled, because parent::getEloquentQuery() applies tenant scoping.
        return Reference::query()->withoutOwnerScope();
    }

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
