<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Venues;

use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;

/**
 * Delete a venue through the owning feature.
 *
 * The venue model delete override invokes the Directory venue-link removal
 * action inside its transaction, so deleting through this action preserves
 * the current lifecycle, observer and media behavior.
 */
final readonly class DeleteVenueAction
{
    public function handle(Model $venue): void
    {
        DirectoryModel::assert($venue, 'venue');

        $venue->delete();
    }
}
