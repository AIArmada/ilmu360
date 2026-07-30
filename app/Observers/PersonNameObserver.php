<?php

declare(strict_types=1);

namespace App\Observers;

use AIArmada\Persons\Models\PersonName;
use App\Models\Person;
use App\Support\Search\PersonSearchService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class PersonNameObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private PersonSearchService $personSearchService) {}

    public function saved(PersonName $personName): void
    {
        $this->syncPerson($personName->person);
    }

    public function deleted(PersonName $personName): void
    {
        $this->syncPerson($personName->person);
    }

    private function syncPerson(mixed $person): void
    {
        if ($person instanceof Person) {
            $this->personSearchService->syncPersonRecord($person->fresh());
        }
    }
}
