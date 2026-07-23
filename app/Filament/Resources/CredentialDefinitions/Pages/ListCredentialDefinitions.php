<?php

namespace App\Filament\Resources\CredentialDefinitions\Pages;

use App\Filament\Resources\CredentialDefinitions\CredentialDefinitionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCredentialDefinitions extends ListRecords
{
    protected static string $resource = CredentialDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
