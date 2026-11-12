<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\Pages;

use App\Features\Directory\Api\PersonWorkflow;
use App\Filament\Resources\Persons\PersonResource;
use App\Models\Person;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreatePerson extends CreateRecord
{
    #[\Override]
    protected static string $resource = PersonResource::class;

    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $address = $data['address'] ?? null;
        unset($data['address']);

        $actorId = auth()->id();

        $personId = app(PersonWorkflow::class)->saveFromAdmin([
            ...Arr::only($data, [
                'name',
                'middle_name',
                'family_name',
                'gender',
                'bio',
                'status',
                'speaker_status',
                'is_featured',
            ]),
            'address' => $address,
        ], (string) $actorId, assignOwner: false);

        return Person::query()->findOrFail($personId);
    }
}
