<?php

declare(strict_types=1);

namespace App\Filament\Ahli\Resources\Institutions\Pages;

use App\Filament\Ahli\Resources\Institutions\InstitutionResource;

class EditInstitution extends \App\Filament\Resources\Institutions\Pages\EditInstitution
{
    protected static string $resource = InstitutionResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [];
    }
}
