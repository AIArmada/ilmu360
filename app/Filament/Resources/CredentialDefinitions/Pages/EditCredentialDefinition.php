<?php

declare(strict_types=1);

namespace App\Filament\Resources\CredentialDefinitions\Pages;

use App\Filament\Resources\CredentialDefinitions\CredentialDefinitionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCredentialDefinition extends EditRecord
{
    protected static string $resource = CredentialDefinitionResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
