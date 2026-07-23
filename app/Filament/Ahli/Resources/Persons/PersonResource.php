<?php

namespace App\Filament\Ahli\Resources\Persons;

use App\Filament\Ahli\Resources\Persons\Pages\EditPerson;
use App\Filament\Ahli\Resources\Persons\Pages\ListPersons;
use App\Filament\Ahli\Resources\Persons\Pages\ViewPerson;
use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\Persons\PersonResource as AdminPersonResource;
use App\Filament\Resources\Persons\RelationManagers\MemberInvitationsRelationManager;
use App\Models\Person;
use App\Models\User;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PersonResource extends AdminPersonResource
{
    protected static string|UnitEnum|null $navigationGroup = 'Directory';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'persons';

    #[\Override]
    public static function table(Table $table): Table
    {
        return parent::table($table)
            ->toolbarActions([]);
    }

    /**
     * @return Builder<Person>
     */
    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Person> $query */
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn(
            'persons.id',
            $user->persons()->select('persons.id')
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
            'index' => ListPersons::route('/'),
            'view' => ViewPerson::route('/{record}'),
            'edit' => EditPerson::route('/{record}/edit'),
        ];
    }
}
