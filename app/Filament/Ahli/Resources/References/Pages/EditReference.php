<?php

declare(strict_types=1);

namespace App\Filament\Ahli\Resources\References\Pages;

use App\Filament\Ahli\Resources\References\ReferenceResource;

class EditReference extends \App\Filament\Resources\References\Pages\EditReference
{
    #[\Override]
    protected static string $resource = ReferenceResource::class;
}
