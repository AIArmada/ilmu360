<?php

declare(strict_types=1);

namespace App\Filament\Resources\AppFeedback\Pages;

use App\Filament\Resources\AppFeedback\AppFeedbackResource;
use Filament\Resources\Pages\ListRecords;

class ListAppFeedback extends ListRecords
{
    protected static string $resource = AppFeedbackResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
