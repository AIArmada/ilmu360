<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Spaces;

use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Delete a space through the owning feature while preserving the event-
 * location guard.
 */
final readonly class DeleteSpaceAction
{
    public function handle(Model $space): void
    {
        DirectoryModel::assert($space, 'space');

        if ($space->eventLocations()->exists()) {
            throw ValidationException::withMessages([
                'space' => __('Spaces referenced by event locations cannot be deleted.'),
            ]);
        }

        $space->delete();
    }
}
