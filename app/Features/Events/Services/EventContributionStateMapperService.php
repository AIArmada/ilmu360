<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use App\Features\Events\Api\Orm\EventContributionStateMapper;
use App\Features\Events\Models\Event;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final readonly class EventContributionStateMapperService implements EventContributionStateMapper
{
    public function toHelperState(array $state): array
    {
        return EventContributionUpdateStateMapper::toHelperState($state);
    }

    public function toPersistenceState(array $state, ?object $event = null): array
    {
        if ($event !== null && ! $event instanceof Event) {
            throw new InvalidArgumentException('Event contribution normalisation requires an event model.');
        }

        return EventContributionUpdateStateMapper::toPersistenceState($state, $event);
    }

    public function provenanceOriginals(object $entity): array
    {
        if (! $entity instanceof Model) {
            throw new InvalidArgumentException('Contribution provenance requires an Eloquent model.');
        }

        return EventContributionUpdateStateMapper::provenanceOriginals($entity);
    }

    public function effectiveOptionsCountry(array $formState, ?object $event = null): string
    {
        if ($event !== null && ! $event instanceof Event) {
            throw new InvalidArgumentException('Event contribution normalisation requires an event model.');
        }

        return EventContributionUpdateStateMapper::effectiveOptionsCountry($formState, $event);
    }
}
