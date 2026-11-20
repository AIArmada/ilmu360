<?php

declare(strict_types=1);

namespace App\Features\Places\Actions\Spaces;

use App\Features\Places\Models\Space;
use App\Features\Places\Persistence\SpaceWriter;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Delete a space through the owning feature while preserving the event-
 * location guard.
 *
 * The Space model keeps its guard and package facility cleanup; the Places
 * writer owns the transactional row delete and institution link cleanup.
 */
final readonly class DeleteSpaceAction
{
    public function __construct(
        private SpaceWriter $spaceWriter,
    ) {}

    public function handle(Model $space): void
    {
        /** @var Space $space */
        $space = MorphModelResolver::assert($space, 'space');

        if ($space->eventLocations()->exists()) {
            throw ValidationException::withMessages([
                'space' => __('Spaces referenced by event locations cannot be deleted.'),
            ]);
        }

        $this->spaceWriter->delete($space);
    }
}
