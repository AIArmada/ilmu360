<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use App\Features\Events\Api\EventContributionStateMapper;
use App\Features\Events\Models\Event;

final readonly class EventContributionStateMapperService implements EventContributionStateMapper
{
    public function toHelperState(array $state): array
    {
        return EventContributionUpdateStateMapper::toHelperState($state);
    }

    public function toPersistenceState(array $state, ?string $eventId = null): array
    {
        return EventContributionUpdateStateMapper::toPersistenceState($state, $eventId !== null ? Event::query()->findOrFail($eventId) : null);
    }

    public function provenanceOriginals(?string $eventId = null): array
    {
        return EventContributionUpdateStateMapper::provenanceOriginals($eventId !== null ? Event::query()->findOrFail($eventId) : null);
    }


}
