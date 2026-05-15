<?php

declare(strict_types=1);

namespace App\Filament\Resources\Countries\Pages;

use App\Filament\Resources\Countries\CountryResource;
use Filament\Resources\Pages\EditRecord;

class EditCountry extends EditRecord
{
    protected static string $resource = CountryResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CountryResource::makeDeleteAction(),
        ];
    }
}
