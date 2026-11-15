<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Authoring;

use AIArmada\Events\Models\EventUpdate;
use App\Features\Events\Api\EventAuthoring;

/**
 * Persist a published change announcement through the package EventUpdate model.
 *
 * @phpstan-import-type EventChangeAnnouncementAttributes from EventAuthoring
 */
final readonly class CreateEventChangeAnnouncementAction
{
    /**
     * @param  EventChangeAnnouncementAttributes  $attributes
     */
    public function handle(array $attributes): string
    {
        $announcement = EventUpdate::query()->create($attributes);

        return (string) $announcement->getKey();
    }
}
