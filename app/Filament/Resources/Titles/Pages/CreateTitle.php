<?php

declare(strict_types=1);

namespace App\Filament\Resources\Titles\Pages;

use App\Filament\Resources\Titles\TitleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTitle extends CreateRecord
{
    protected static string $resource = TitleResource::class;
}
