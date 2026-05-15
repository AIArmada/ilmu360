<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiUsageLogs\Pages;

use App\Filament\Resources\AiUsageLogs\AiUsageLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAiUsageLogs extends ListRecords
{
    protected static string $resource = AiUsageLogResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [];
    }
}
