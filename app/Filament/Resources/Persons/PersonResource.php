<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons;

use AIArmada\FilamentPersons\Resources\PersonResource as PackagePersonResource;
use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\Persons\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Persons\RelationManagers\FollowersRelationManager;
use App\Filament\Resources\Persons\RelationManagers\MemberInvitationsRelationManager;
use App\Filament\Resources\Persons\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Persons\Schemas\PersonForm;
use App\Filament\Resources\Persons\Tables\PersonsTable;
use App\Models\Person;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class PersonResource extends PackagePersonResource
{
    protected static ?string $model = Person::class;

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
    public static function getRelations(): array
    {
        return [
            ...parent::getRelations(),
            MembersRelationManager::class,
            MemberInvitationsRelationManager::class,
            FollowersRelationManager::class,
            RelationManagers\InstitutionsRelationManager::class,
            EventsRelationManager::class,
            AuditsRelationManager::class,
        ];
    }
}
