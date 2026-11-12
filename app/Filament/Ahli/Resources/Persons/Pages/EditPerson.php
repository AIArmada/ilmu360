<?php

declare(strict_types=1);

namespace App\Filament\Ahli\Resources\Persons\Pages;

use App\Features\Directory\Api\PersonWorkflow;
use App\Filament\Ahli\Resources\Persons\PersonResource;
use App\Support\Persons\PersonBioContent;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditPerson extends EditRecord
{
    #[\Override]
    protected static string $resource = PersonResource::class;

    #[\Override]
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['bio'] = PersonBioContent::toRichContent($data['bio'] ?? null);

        return $data;
    }

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actorId = auth()->id();

        app(PersonWorkflow::class)->saveFromAdmin(
            Arr::only($data, [
                'name',
                'middle_name',
                'family_name',
                'gender',
                'bio',
                'status',
                'speaker_status',
                'is_featured',
                'allow_public_event_submission',
            ]),
            (string) $actorId,
            (string) $record->getKey(),
        );

        $record->refresh();

        return $record;
    }
}
