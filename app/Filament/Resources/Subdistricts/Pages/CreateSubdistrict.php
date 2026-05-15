<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subdistricts\Pages;

use App\Filament\Resources\Subdistricts\SubdistrictResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSubdistrict extends CreateRecord
{
    protected static string $resource = SubdistrictResource::class;
}
