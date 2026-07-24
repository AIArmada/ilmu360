<?php

declare(strict_types=1);

namespace App\Filament\Resources\TitleIssuers\Pages;

use App\Filament\Resources\TitleIssuers\TitleIssuerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTitleIssuers extends ListRecords
{
    protected static string $resource = TitleIssuerResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
