<?php

declare(strict_types=1);

namespace App\Features\Institutions\EntryPoints\Filament\Ahli\Resources\Institutions\Pages;

use App\Features\Institutions\EntryPoints\Filament\Ahli\Resources\Institutions\InstitutionResource;

class EditInstitution extends \App\Features\Institutions\EntryPoints\Filament\Resources\Institutions\Pages\EditInstitution
{
    #[\Override]
    protected static string $resource = InstitutionResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [];
    }
}
