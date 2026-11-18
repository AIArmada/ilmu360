<?php

declare(strict_types=1);

namespace App\Features\References\EntryPoints\Filament\Ahli\Resources\References\Pages;

use App\Features\References\EntryPoints\Filament\Ahli\Resources\References\ReferenceResource;
use Filament\Resources\Pages\ListRecords;

class ListReferences extends ListRecords
{
    #[\Override]
    protected static string $resource = ReferenceResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [];
    }
}
