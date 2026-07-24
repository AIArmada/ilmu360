<?php

declare(strict_types=1);

namespace App\Filament\Resources\Titles\Pages;

use App\Filament\Resources\Titles\TitleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTitles extends ListRecords
{
    protected static string $resource = TitleResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
