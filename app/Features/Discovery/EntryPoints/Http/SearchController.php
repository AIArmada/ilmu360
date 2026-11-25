<?php

declare(strict_types=1);

namespace App\Features\Discovery\EntryPoints\Http;

use App\Features\Discovery\Api\Data\EventListData;
use App\Features\Discovery\Api\EventSearchReader;
use App\Features\Institutions\Api\InstitutionCards;
use App\Features\Persons\Api\PersonCards;
use App\Models\User;
use App\Platform\Api\Search\SearchRequestNormalizer;
use App\Platform\Http\FrontendController;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SearchController extends FrontendController
{
    public function __construct(
        private readonly EventSearchReader $eventSearch,
        private readonly InstitutionCards $institutionCards,
        private readonly PersonCards $personCards,
        private readonly SearchRequestNormalizer $searchRequestNormalizer,
    ) {}

    #[Group('Search', 'Public aggregate search endpoints across events, persons, and institutions.')]
    #[Endpoint(
        title: 'Search events, persons, and institutions',
        description: 'Returns a compact public search payload for events, persons, and institutions using the same visibility rules as the client surface.',
    )]
    #[QueryParameter('search', 'Optional free-text search query across public events, persons, and institutions.', required: false, type: 'string', infer: false, example: 'Kuliah')]
    public function search(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $search = $this->searchRequestNormalizer->normalizedString($request->query('search'));
        $coordinates = $this->searchRequestNormalizer->resolvedNearbyCoordinates($request);
        $lat = $coordinates['lat'];
        $lng = $coordinates['lng'];
        $radius = $this->searchRequestNormalizer->normalizedRadiusKm($request);
        $bbox = $this->searchRequestNormalizer->resolvedBoundingBox($request);
        $eventFilters = $bbox !== null ? ['bbox' => $bbox] : [];
        $hasLocation = $lat !== null && $lng !== null;

        $eventPage = $hasLocation
            ? ($search !== null
                ? $this->eventSearch->searchNearbyWithQuery(
                    query: $search,
                    lat: $lat ?? 0.0,
                    lng: $lng ?? 0.0,
                    radiusKm: $radius,
                    filters: $eventFilters,
                    perPage: 6,
                )
                : $this->eventSearch->searchNearby(
                    lat: $lat ?? 0.0,
                    lng: $lng ?? 0.0,
                    radiusKm: $radius,
                    filters: $eventFilters,
                    perPage: 6,
                ))
            : $this->eventSearch->search(
                query: $search,
                filters: $eventFilters,
                perPage: 6,
                sort: $search !== null ? 'relevance' : 'time',
            );

        $institutionCards = $search !== null
            ? $this->institutionCards->forSearch(
                query: $search,
                lat: $hasLocation ? $lat : null,
                lng: $hasLocation ? $lng : null,
                radiusKm: $radius,
                limit: 4,
            )
            : ['items' => [], 'total' => 0];

        $personCards = $search !== null
            ? $this->personCards->forSearch(
                query: $search,
                accountId: $user instanceof User ? (string) $user->getKey() : null,
                limit: 4,
            )
            : ['items' => [], 'total' => 0];

        return response()->json([
            'data' => [
                'events' => [
                    'items' => array_map(
                        static fn (EventListData $event): array => $event->toArray(),
                        $eventPage->items,
                    ),
                    'total' => $eventPage->total,
                ],
                'persons' => $personCards,
                'institutions' => $institutionCards,
            ],
            'meta' => [
                'search' => $search,
                'lat' => $lat,
                'lng' => $lng,
                'radius_km' => $radius,
                'authenticated' => $user instanceof User,
            ],
        ]);
    }
}
