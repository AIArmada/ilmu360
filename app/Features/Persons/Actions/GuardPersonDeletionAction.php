<?php

declare(strict_types=1);

namespace App\Features\Persons\Actions;

use AIArmada\References\Models\ReferenceContributor;
use App\Features\Persons\Models\Person;
use Illuminate\Validation\ValidationException;

final readonly class GuardPersonDeletionAction
{
    public function handle(Person $person): void
    {
        if (ReferenceContributor::query()
            ->where('contributor_type', $person->getMorphClass())
            ->where('contributor_id', $person->getKey())
            ->exists()) {
            throw ValidationException::withMessages([
                'person' => __('This person is linked as a reference contributor and cannot be deleted.'),
            ]);
        }
    }
}
