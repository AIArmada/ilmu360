<?php

declare(strict_types=1);

namespace App\Filament\Ahli\Resources\Persons\Pages;

use App\Filament\Ahli\Resources\Persons\PersonResource;
use App\Support\Persons\PersonBioContent;
use Filament\Resources\Pages\EditRecord;

class EditPerson extends EditRecord
{
    protected static string $resource = PersonResource::class;

    #[\Override]
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['bio'] = PersonBioContent::toRichContent($data['bio'] ?? null);

        return $data;
    }
}
