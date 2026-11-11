<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Institutions;

use Illuminate\Database\Eloquent\Model;

/**
 * Delete an institution through the owning feature.
 *
 * The model delete override invokes the Directory bridge actions for both
 * institution_venue and institution_space inside its transaction, so this
 * action is the single entry point callers (including Filament) must use for
 * institution deletes and bridge cleanup cannot be skipped.
 */
final readonly class DeleteInstitutionAction
{
    public function handle(Model $institution): void
    {
        $institution->delete();
    }
}
