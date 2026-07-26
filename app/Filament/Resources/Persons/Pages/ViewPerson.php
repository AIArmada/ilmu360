<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\Pages;

use AIArmada\FilamentPersons\Resources\PersonResource\Pages\ViewPerson as PackageViewPerson;
use App\Filament\Resources\Persons\PersonResource;

class ViewPerson extends PackageViewPerson
{
    protected static string $resource = PersonResource::class;
}
