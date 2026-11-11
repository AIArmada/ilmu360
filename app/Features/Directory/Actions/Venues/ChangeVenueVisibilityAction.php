<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Venues;

use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Apply an explicit venue visibility transition.
 */
final readonly class ChangeVenueVisibilityAction
{
    public function handle(string $venueId, string $visibility): void
    {
        if (! in_array($visibility, ['public', 'unlisted', 'private'], true)) {
            throw new InvalidArgumentException("Unknown venue visibility [{$visibility}].");
        }

        $venue = DirectoryModel::query('venue')->find($venueId);

        if (! $venue instanceof Model) {
            throw new InvalidArgumentException("Venue [{$venueId}] was not found.");
        }

        $venue->setAttribute('visibility', $visibility);
        $venue->save();
    }
}
