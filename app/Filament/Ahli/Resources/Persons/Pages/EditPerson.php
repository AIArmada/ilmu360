<?php

declare(strict_types=1);

namespace App\Filament\Ahli\Resources\Persons\Pages;

use App\Filament\Ahli\Resources\Persons\PersonResource;
use Filament\Resources\Pages\EditRecord;

class EditPerson extends EditRecord
{
    protected static string $resource = PersonResource::class;
}
