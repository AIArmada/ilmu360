<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContributionRequests\Pages;

use App\Filament\Resources\ContributionRequests\ContributionRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListContributionRequests extends ListRecords
{
    protected static string $resource = ContributionRequestResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [];
    }
}
