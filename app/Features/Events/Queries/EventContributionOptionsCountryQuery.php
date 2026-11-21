<?php

declare(strict_types=1);

namespace App\Features\Events\Queries;

use App\Features\Events\Api\EventContributionOptionsCountry;
use App\Features\Events\Models\Event;
use App\Features\Events\Services\EventContributionUpdateStateMapper;

final readonly class EventContributionOptionsCountryQuery implements EventContributionOptionsCountry
{
    public function forFormState(array $formState, ?string $eventId = null): string
    {
        return EventContributionUpdateStateMapper::effectiveOptionsCountry(
            $formState,
            $eventId !== null ? Event::query()->find($eventId) : null,
        );
    }
}
