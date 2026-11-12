<?php

declare(strict_types=1);

namespace App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback\Pages;

use App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback\AppFeedbackResource;
use Filament\Resources\Pages\ListRecords;

class ListAppFeedback extends ListRecords
{
    #[\Override]
    protected static string $resource = AppFeedbackResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
