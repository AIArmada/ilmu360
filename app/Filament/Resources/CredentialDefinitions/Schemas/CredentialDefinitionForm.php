<?php

namespace App\Filament\Resources\CredentialDefinitions\Schemas;

use App\Enums\CredentialType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CredentialDefinitionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Credential Definition'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('short_form')
                            ->label(__('Short Form'))
                            ->maxLength(50),
                        TextInput::make('field')
                            ->label(__('Field'))
                            ->maxLength(255),
                        Select::make('credential_type')
                            ->label(__('Credential Type'))
                            ->options(CredentialType::class)
                            ->required(),
                        TextInput::make('language_code')
                            ->label(__('Language Code'))
                            ->maxLength(10),
                    ]),
            ]);
    }
}
