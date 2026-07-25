<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\Pages;

use App\Filament\Resources\Persons\PersonResource;
use App\Models\Person;
use App\Services\ContributionEntityMutationService;
use Filament\Resources\Pages\CreateRecord;

class CreatePerson extends CreateRecord
{
    protected static string $resource = PersonResource::class;

    protected function getCreatedModel(): Person
    {
        /** @var Person $person */
        $person = parent::getCreatedModel();

        $address = $this->form->getState()['address'] ?? null;
        if ($address !== null) {
            app(ContributionEntityMutationService::class)->syncPersonRelations($person, ['address' => $address]);
        }

        return $person;
    }
}
