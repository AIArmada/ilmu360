<?php

namespace App\Filament\Ahli\Resources\References;

use App\Filament\Ahli\Resources\References\Pages\EditReference;
use App\Filament\Ahli\Resources\References\Pages\ListReferences;
use App\Filament\Resources\References\ReferenceResource as AdminReferenceResource;
use App\Filament\Resources\References\RelationManagers\MemberInvitationsRelationManager;
use App\Models\Reference;
use App\Platform\Auditing\AuditsRelationManager;
use App\Shared\Auth\AccountActor;
use App\Shared\Membership\MembershipDirectory;
use App\Shared\Membership\MembershipSubjectType;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ReferenceResource extends AdminReferenceResource
{
    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'Directory';

    #[\Override]
    protected static ?int $navigationSort = 40;

    #[\Override]
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

        if (! $user instanceof AccountActor) {
            return $query->whereRaw('1 = 0');
        }

        $subjectIds = app(MembershipDirectory::class)->subjectIdsFor(
            $user->actorId(),
            MembershipSubjectType::Reference,
        );

        return $query->whereIn('references.id', $subjectIds);
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
