<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subdistricts\Pages;

use App\Filament\Resources\Subdistricts\SubdistrictResource;
use Filament\Resources\Pages\EditRecord;

class EditSubdistrict extends EditRecord
{
    protected static string $resource = SubdistrictResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            SubdistrictResource::makeDeleteAction(),
        ];
    }
}
