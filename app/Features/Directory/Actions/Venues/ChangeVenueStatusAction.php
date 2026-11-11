<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Venues;

use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Apply an explicit venue lifecycle transition.
 */
final readonly class ChangeVenueStatusAction
{
    public function handle(string $venueId, string $status): void
    {
        $venue = DirectoryModel::query('venue')->find($venueId);

        if (! $venue instanceof Model) {
            throw new RuntimeException("Venue [{$venueId}] was not found.");
        }

        $venue->setAttribute('status', $status);
        $venue->save();
    }
}
