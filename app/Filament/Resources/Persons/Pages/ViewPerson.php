<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\Pages;

use App\Filament\Resources\Persons\PersonResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPerson extends ViewRecord
{
    protected static string $resource = PersonResource::class;
}
