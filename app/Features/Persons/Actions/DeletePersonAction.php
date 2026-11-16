<?php

declare(strict_types=1);

namespace App\Features\Persons\Actions;

use App\Models\Person;

/**
 * Delete a person through the owning feature.
 *
 * The model keeps its cascade/cleanup hooks, so this action is the single
 * entry point callers (including Filament and pruning) must use for deletes.
 */
final readonly class DeletePersonAction
{
    public function handle(Person $person): void
    {
        $person->delete();
    }
}
