<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Spaces;

use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Apply an explicit space visibility transition.
 */
final readonly class ChangeSpaceVisibilityAction
{
    public function handle(string $spaceId, string $visibility): void
    {
        $visibility = trim($visibility);

        if (! in_array($visibility, ['public', 'unlisted', 'private'], true)) {
            throw ValidationException::withMessages([
                'visibility' => __('The selected visibility is invalid.'),
            ]);
        }

        $space = DirectoryModel::query('space')->find($spaceId);

        if (! $space instanceof Model) {
            throw new RuntimeException("Space [{$spaceId}] was not found.");
        }

        $space->setAttribute('visibility', $visibility);
        $space->save();
    }
}
