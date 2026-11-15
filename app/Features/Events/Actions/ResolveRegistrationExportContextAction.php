<?php

declare(strict_types=1);

namespace App\Features\Events\Actions;

use App\Features\Events\Api\RegistrationExportAccess;
use App\Features\Events\Api\RegistrationExportContext;
use App\Features\Events\Support\EventModel;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class ResolveRegistrationExportContextAction implements RegistrationExportAccess
{
    use AsAction;

    public function resolve(string $eventId): ?RegistrationExportContext
    {
        $eventClass = EventModel::event();
        $event = $eventClass::query()->find($eventId);

        if ($event === null) {
            return null;
        }

        return new RegistrationExportContext(
            eventId: (string) $event->getKey(),
            slug: (string) $event->getAttribute('slug'),
            eventClass: $event::class,
            canExport: ! Gate::denies('exportRegistrations', $event),
        );
    }
}
