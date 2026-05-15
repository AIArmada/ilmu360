<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inspirations\Pages;

use App\Filament\Resources\Inspirations\InspirationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInspiration extends CreateRecord
{
    protected static string $resource = InspirationResource::class;
}
