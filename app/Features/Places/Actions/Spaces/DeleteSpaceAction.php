<?php

declare(strict_types=1);

namespace App\Features\Places\Actions\Spaces;

use App\Features\Places\Models\Space;
use App\Features\Places\Persistence\SpaceWriter;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Delete a space through the owning feature while preserving the event-
 * location guard.
 *
 * The model's pre-delete hook delegates the guard to GuardSpaceDeletionAction;
 * the writer then performs the transactional delete and link cleanup.
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

        $this->spaceWriter->delete($space);
    }
}
