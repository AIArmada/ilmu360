<?php

declare(strict_types=1);

namespace App\Features\Places\Actions\Spaces;

use App\Features\Places\Models\Space;
use Illuminate\Validation\ValidationException;

/** Prevent all deletion pathways from orphaning referenced event locations. */
final readonly class GuardSpaceDeletionAction
{
    public function handle(Space $space): void
    {
        if ($space->eventLocations()->exists()) {
            throw ValidationException::withMessages([
                'space' => __('Spaces referenced by event locations cannot be deleted.'),
            ]);
        }
    }
}
