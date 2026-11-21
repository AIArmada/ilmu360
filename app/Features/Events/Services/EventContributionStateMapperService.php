<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use App\Features\Events\Api\Orm\EventContributionStateMapper;
use App\Features\Events\Models\Event;
use Illuminate\Database\Eloquent\Model;

final readonly class EventContributionStateMapperService implements EventContributionStateMapper
{
    public function toHelperState(array $state): array
    {
        return EventContributionUpdateStateMapper::toHelperState($state);
    }

    public function toPersistenceState(array $state, ?Event $event = null): array
    {
        return EventContributionUpdateStateMapper::toPersistenceState($state, $event);
    }

    public function provenanceOriginals(Model $entity): array
    {
        return EventContributionUpdateStateMapper::provenanceOriginals($entity);
    }

    public function effectiveOptionsCountry(array $formState, ?Event $event = null): string
    {
        return EventContributionUpdateStateMapper::effectiveOptionsCountry($formState, $event);
    }
}
