<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\Pages;

use App\Filament\Resources\Persons\PersonResource;
use App\Models\Person;
use App\Services\ContributionEntityMutationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePerson extends CreateRecord
{
    protected static string $resource = PersonResource::class;

    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $address = $data['address'] ?? null;
        unset($data['address']);

        /** @var Person $person */
        $person = parent::handleRecordCreation($data);

        if ($address !== null) {
            app(ContributionEntityMutationService::class)->syncPersonRelations($person, ['address' => $address]);
        }

        return $person;
    }
}
