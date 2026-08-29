<?php

declare(strict_types=1);

namespace App\Support\EventDiscovery;

use AIArmada\Events\Contracts\EventSearchRelationProvider;
use AIArmada\Events\Resolvers\DefaultEventSearchRelationProvider;
use App\Models\Event;

final class EventCardRelationshipProvider implements EventSearchRelationProvider
{
    public function __construct(
        private readonly DefaultEventSearchRelationProvider $packageProvider,
    ) {}

    /**
     * @return array<int|string, mixed>
     */
    public function relations(): array
    {
        return [
            ...$this->packageProvider->relations(),
            'media' => fn ($query) => $query
                ->whereIn('collection_name', ['cover', 'poster'])
                ->ordered(),
            'references' => fn ($query) => $query->active(),
            'persons.media' => fn ($query) => $query
                ->where('collection_name', 'avatar')
                ->ordered(),
            'persons.titleAssignments.title.category',
            'languageRecords',
            'institution.media' => fn ($query) => $query
                ->where('collection_name', 'logo')
                ->ordered(),
            'institution.addresses.country',
            'institution.addresses.state',
            'institution.addresses.city',
            'institution.addresses.areaAssignments.area',
            'venue.addresses.country',
            'venue.addresses.state',
            'venue.addresses.city',
            'venue.addresses.areaAssignments.area',
            'primaryLocation.venueSpace',
            'latestPublishedChangeAnnouncement',
            'primaryOccurrence' => fn ($query) => $query
                ->whereIn('status', Event::PUBLIC_SCHEDULE_STATUSES)
                ->whereIn('visibility', Event::PUBLIC_SCHEDULE_VISIBILITIES),
            'primaryOccurrence.timeExpressions',
            'primaryOccurrence.sessions' => fn ($query) => $query
                ->whereIn('status', Event::PUBLIC_SCHEDULE_STATUSES)
                ->whereIn('visibility', Event::PUBLIC_SCHEDULE_VISIBILITIES),
            'primaryOccurrence.sessions.timeExpressions',
            'primaryOccurrence.sessions.involvements' => fn ($query) => $query
                ->where('status', 'active')
                ->where('visibility', 'public'),
            'primaryOccurrence.sessions.involvements.involveable',
        ];
    }
}
