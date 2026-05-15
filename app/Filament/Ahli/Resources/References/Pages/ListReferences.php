<?php

declare(strict_types=1);

namespace App\Filament\Ahli\Resources\References\Pages;

use App\Filament\Ahli\Resources\References\ReferenceResource;
use Filament\Resources\Pages\ListRecords;

class ListReferences extends ListRecords
{
    protected static string $resource = ReferenceResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [];
    }
}
