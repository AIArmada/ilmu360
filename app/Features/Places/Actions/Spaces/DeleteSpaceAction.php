<?php

declare(strict_types=1);

namespace App\Features\Places\Actions\Spaces;

use App\Models\Space;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Delete a space through the owning feature while preserving the event-
 * location guard.
 *
 * The space model delete override detaches institution_space rows through
 * the Places link action inside its transaction.
 */
final readonly class DeleteSpaceAction
{
    public function handle(Model $space): void
    {
        /** @var Space $space */
        $space = MorphModelResolver::assert($space, 'space');

        if ($space->eventLocations()->exists()) {
            throw ValidationException::withMessages([
                'space' => __('Spaces referenced by event locations cannot be deleted.'),
            ]);
        }

        $space->delete();
    }
}
