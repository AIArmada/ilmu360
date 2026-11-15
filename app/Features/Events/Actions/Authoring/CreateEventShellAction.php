<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Authoring;

use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Support\EventModel;

/**
 * @phpstan-import-type EventShellAttributes from EventAuthoring
 */
final readonly class CreateEventShellAction
{
    /**
     * @param  EventShellAttributes  $attributes
     */
    public function handle(array $attributes): string
    {
        $event = EventModel::query('event')->create($attributes);

        return (string) $event->getKey();
    }
}
