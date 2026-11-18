<?php

declare(strict_types=1);

namespace App\Features\Institutions\EntryPoints\Filament\Resources\Institutions\Pages;

use App\Features\Institutions\EntryPoints\Filament\Resources\Institutions\InstitutionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInstitutions extends ListRecords
{
    #[\Override]
    protected static string $resource = InstitutionResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
