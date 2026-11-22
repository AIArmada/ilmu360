<?php

declare(strict_types=1);

namespace App\Features\Persons\Actions\Projection;

use AIArmada\Persons\Models\PersonName;
use App\Features\Persons\Models\Person;
use App\Features\Persons\Services\PersonSearchService;

final readonly class SyncPersonNameSearchAction
{
    public function __construct(private PersonSearchService $personSearchService) {}

    public function handle(PersonName $name): void
    {
        $person = $name->person;

        if ($person instanceof Person) {
            $this->personSearchService->syncPersonRecord($person->fresh());
        }
    }
}
