<?php

namespace App\Filament\Resources\CredentialDefinitions;

use App\Filament\Resources\CredentialDefinitions\Pages\CreateCredentialDefinition;
use App\Filament\Resources\CredentialDefinitions\Pages\EditCredentialDefinition;
use App\Filament\Resources\CredentialDefinitions\Pages\ListCredentialDefinitions;
use App\Filament\Resources\CredentialDefinitions\Schemas\CredentialDefinitionForm;
use App\Filament\Resources\CredentialDefinitions\Tables\CredentialDefinitionsTable;
use App\Models\CredentialDefinition;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CredentialDefinitionResource extends Resource
{
    protected static ?string $model = CredentialDefinition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return CredentialDefinitionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CredentialDefinitionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCredentialDefinitions::route('/'),
            'create' => CreateCredentialDefinition::route('/create'),
            'edit' => EditCredentialDefinition::route('/{record}/edit'),
        ];
    }
}
