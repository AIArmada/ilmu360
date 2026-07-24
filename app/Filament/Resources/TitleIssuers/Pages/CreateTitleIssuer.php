<?php

declare(strict_types=1);

namespace App\Filament\Resources\TitleIssuers\Pages;

use App\Filament\Resources\TitleIssuers\TitleIssuerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTitleIssuer extends CreateRecord
{
    protected static string $resource = TitleIssuerResource::class;
}
