<?php

declare(strict_types=1);

namespace App\Filament\Resources\SlugRedirects\Pages;

use App\Filament\Resources\SlugRedirects\SlugRedirectResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSlugRedirects extends ListRecords
{
    protected static string $resource = SlugRedirectResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
