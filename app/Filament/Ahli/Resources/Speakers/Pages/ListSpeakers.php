<?php

declare(strict_types=1);

namespace App\Filament\Ahli\Resources\Speakers\Pages;

use App\Filament\Ahli\Resources\Speakers\SpeakerResource;
use Filament\Resources\Pages\ListRecords;

class ListSpeakers extends ListRecords
{
    protected static string $resource = SpeakerResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [];
    }
}
