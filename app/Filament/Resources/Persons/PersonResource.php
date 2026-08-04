<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons;

use AIArmada\FilamentPersons\Resources\PersonResource as PackagePersonResource;
use AIArmada\FilamentPersons\Resources\PersonResource\RelationManagers\AffiliationsRelationManager as PackageAffiliationsRelationManager;
use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\Persons\Pages\CreatePerson;
use App\Filament\Resources\Persons\Pages\EditPerson;
use App\Filament\Resources\Persons\Pages\ListPersons;
use App\Filament\Resources\Persons\Pages\ViewPerson;
use App\Filament\Resources\Persons\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Persons\RelationManagers\FollowersRelationManager;
use App\Filament\Resources\Persons\RelationManagers\MemberInvitationsRelationManager;
use App\Filament\Resources\Persons\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Persons\Schemas\PersonForm;
use App\Filament\Resources\Persons\Schemas\PersonInfolist;
use App\Filament\Resources\Persons\Tables\PersonsTable;
use App\Models\Person;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class PersonResource extends PackagePersonResource
{
    protected static ?string $model = Person::class;

    protected static ?string $slug = 'persons';

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return PersonForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return PersonsTable::configure($table);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return PersonInfolist::configure($schema);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListPersons::route('/'),
            'create' => CreatePerson::route('/create'),
            'view' => ViewPerson::route('/{record}'),
            'edit' => EditPerson::route('/{record}/edit'),
        ];
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            ...array_filter(parent::getRelations(), fn (string $class): bool => ! in_array($class, [
                PackageAffiliationsRelationManager::class,
            ], true)),
            MembersRelationManager::class,
            MemberInvitationsRelationManager::class,
            FollowersRelationManager::class,
            RelationManagers\AffiliationsRelationManager::class,
            EventsRelationManager::class,
            AuditsRelationManager::class,
        ];
    }
}
