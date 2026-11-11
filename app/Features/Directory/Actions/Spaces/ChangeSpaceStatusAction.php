<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Spaces;

use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Apply an explicit space status transition.
 */
final readonly class ChangeSpaceStatusAction
{
    public function handle(string $spaceId, string $status): void
    {
        $status = trim($status);

        if (! in_array($status, ['active', 'inactive'], true)) {
            throw ValidationException::withMessages([
                'status' => __('The selected status is invalid.'),
            ]);
        }

        $space = DirectoryModel::query('space')->find($spaceId);

        if (! $space instanceof Model) {
            throw new RuntimeException("Space [{$spaceId}] was not found.");
        }

        $space->setAttribute('status', $status);
        $space->save();
    }
}
