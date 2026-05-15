<?php

namespace App\Filament\Ahli\Resources\References;

use App\Filament\Ahli\Resources\References\Pages\EditReference;
use App\Filament\Ahli\Resources\References\Pages\ListReferences;
use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\References\ReferenceResource as AdminReferenceResource;
use App\Filament\Resources\References\RelationManagers\MemberInvitationsRelationManager;
use App\Models\Reference;
use App\Models\User;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ReferenceResource extends AdminReferenceResource
{
    protected static string|UnitEnum|null $navigationGroup = 'Directory';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'references';

    #[\Override]
    public static function table(Table $table): Table
    {
        return parent::table($table)
            ->toolbarActions([]);
    }

    /**
     * @return Builder<Reference>
     */
    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Reference> $query */
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn(
            'references.id',
            $user->references()->select('references.id')
        );
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            MemberInvitationsRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListReferences::route('/'),
            'edit' => EditReference::route('/{record}/edit'),
        ];
    }
}
