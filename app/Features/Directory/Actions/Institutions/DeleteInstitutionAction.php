<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Institutions;

use Illuminate\Database\Eloquent\Model;

/**
 * Delete an institution through the owning feature.
 *
 * The model keeps its cascade/cleanup hooks, so this action is the single
 * entry point callers (including Filament) must use for institution deletes.
 */
final readonly class DeleteInstitutionAction
{
    public function handle(Model $institution): void
    {
        $institution->delete();
    }
}
