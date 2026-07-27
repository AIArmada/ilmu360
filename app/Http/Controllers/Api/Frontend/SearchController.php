<?php

namespace App\Http\Controllers\Api\Frontend;

use AIArmada\Addressing\Data\AddressLocationData;
use AIArmada\Addressing\Support\AddressLocationScope;
use App\Data\Api\Frontend\Search\EventListData;
use App\Data\Api\Frontend\Search\InstitutionDetailData;
use App\Data\Api\Frontend\Search\InstitutionDonationChannelData;
use App\Data\Api\Frontend\Search\InstitutionListData;
use App\Data\Api\Frontend\Search\PersonDetailData;
use App\Data\Api\Frontend\Search\PersonDetailMediaData;
use App\Data\Api\Frontend\Search\PersonGalleryItemData;
use App\Data\Api\Frontend\Search\PersonInstitutionData;
use App\Data\Api\Frontend\Search\PersonListData;
use App\Data\Api\Frontend\Search\ReferenceDetailData;
use App\Data\Api\Frontend\Search\ReferenceListData;
use App\Data\Api\Frontend\Search\SeriesDetailData;
use App\Data\Api\Frontend\Search\VenueDetailData;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventVisibility;
use App\Enums\InspirationCategory;
use App\Enums\InstitutionType;
use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Inspiration;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Series;
use App\Models\User;
use App\Models\Venue;
use App\Services\EventSearchService;
use App\Support\Api\ApiPagination;
use App\Support\Api\Frontend\SearchPayloadTransformer;
use App\Support\Api\Frontend\SearchRequestNormalizer;
use App\Support\ApiDocumentation\Schemas\InstitutionDetailResponse;
use App\Support\ApiDocumentation\Schemas\InstitutionDirectoryResponse;
use App\Support\ApiDocumentation\Schemas\PersonDetailResponse;
use App\Support\ApiDocumentation\Schemas\PersonDirectoryResponse;
use App\Support\ApiDocumentation\Schemas\ReferenceDirectoryResponse;
use App\Support\Cache\PublicDirectoryCacheVersion;
use App\Support\Models\SlugOrUuidResolver;
use App\Support\PublicDiscovery\PublicDiscovery;
use App\Support\Search\InstitutionSearchService;
use App\Support\Search\PersonSearchService;
use App\Support\Search\ReferenceSearchService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class SearchController extends FrontendController
{
    /** @var list<string> */
    private const array INSTITUTION_LIST_FIELDS = [
        'id',
        'slug',
        'name',
        'type',
        'nickname',
        'display_name',
        'events_count',
        'public_image_url',
        'logo_url',
        'cover_url',
        'country',
        'location',
        'distance_km',
        'is_following',
    ];

    /** @var list<string> */
    private const array REFERENCE_LIST_FIELDS = [
        'id',
        'slug',
        'title',
        'display_title',
        'author',
        'type',
        'parent_reference_id',
        'part_type',
        'part_number',
        'part_label',
        'is_part',
        'publisher',
        'publication_year',
        'status',
        'events_count',
        'front_cover_url',
        'is_following',
    ];

    /** @var list<string> */
    private const array PERSON_LIST_FIELDS = [
        'id',
        'slug',
        'name',
        'gender',
        'formatted_name',
        'status',
        'events_count',
        'avatar_url',
        'country',
        'is_following',
    ];

    public function __construct(
        private readonly EventSearchService $eventSearchService,
        private readonly InstitutionSearchService $institutionSearchService,
        private readonly ReferenceSearchService $referenceSearchService,
        private readonly PersonSearchService $personSearchService,
        private readonly PublicDirectoryCacheVersion $publicDirectoryCacheVersion,
        private readonly SearchRequestNormalizer $searchRequestNormalizer,
        private readonly SearchPayloadTransformer $searchPayloadTransformer,
        private readonly SlugOrUuidResolver $slugOrUuidResolver,
        private readonly AddressLocationScope $addressLocationScope,
        private readonly PublicDiscovery $publicDiscovery,
    ) {}

    #[Group('Search', 'Public aggregate search endpoints across events, persons, and institutions.')]
    #[Endpoint(
        title: 'Search events, persons, and institutions',
        description: 'Returns a compact public search payload for events, persons, and institutions using the same visibility rules as the client surface.',
    )]
    #[QueryParameter('search', 'Optional free-text search query across public events, persons, and institutions.', required: false, type: 'string', infer: false, example: 'Kuliah')]
    #[QueryParameter('q', 'Alias for `search`, accepted for clients that use common search-query naming.', required: false, type: 'string', infer: false, example: 'Kuliah')]
    public function search(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $search = $this->searchRequestNormalizer->normalizedString($request->query('search'))
            ?? $this->searchRequestNormalizer->normalizedString($request->query('q'));
        $coordinates = $this->searchRequestNormalizer->resolvedNearbyCoordinates($request);
        $lat = $coordinates['lat'];
        $lng = $coordinates['lng'];
        $radius = $this->searchRequestNormalizer->normalizedRadiusKm($request);
        $hasLocation = $lat !== null && $lng !== null;

        $eventPaginator = $hasLocation
            ? $this->eventSearchService->searchNearbyWithQuery(
                query: $search,
                lat: $lat ?? 0.0,
                lng: $lng ?? 0.0,
                radiusKm: $radius,
                perPage: 6,
            )
            : $this->eventSearchService->search(
                query: $search,
                perPage: 6,
                sort: $search !== null ? 'relevance' : 'time',
            );

        $personIds = $search !== null ? $this->personSearchService->resolvedPublicSearchIds($search) : [];
        $personQuery = $search !== null
            ? $this->basePersonQuery($user)->whereIn('persons.id', $personIds)
            : Person::query()->whereRaw('1 = 0');
        $institutionIds = $search !== null ? $this->institutionSearchService->resolvedPublicSearchIds($search) : [];
        $institutionQuery = $search !== null
            ? $this->aggregateInstitutionSearchItemsQuery($institutionIds)
            : Institution::query()->whereRaw('1 = 0');

        if ($hasLocation) {
            $this->applyInstitutionNearbyScope($institutionQuery, $lat, $lng, $radius);
        }

        $institutionTotal = $search === null
            ? 0
            : ($hasLocation ? (clone $institutionQuery)->count() : count($institutionIds));

        return response()->json([
            'data' => [
                'events' => [
                    'items' => collect($eventPaginator->items())->map(fn (Event $event): array => $this->eventListData($event))->all(),
                    'total' => $eventPaginator->total(),
                ],
                'persons' => [
                    'items' => $personQuery->orderBy('name')->limit(4)->get()->map(fn (Person $person): array => $this->personListData($person, $user))->all(),
                    'total' => count($personIds),
                ],
                'institutions' => [
                    'items' => $institutionQuery->orderBy('name')->limit(4)->get()->map(fn (Institution $institution): array => $this->institutionListData($institution))->all(),
                    'total' => $institutionTotal,
                ],
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

    #[Group('Institution', 'Public institution directory and detail endpoints.')]
    #[Endpoint(
        title: 'List public institutions',
        description: 'Returns the public institution directory with search, geography, nearby radius, type, and follow-state filters.',
    )]
    #[QueryParameter('near', 'Optional nearby coordinates in `lat,lng` form. Acts as a convenience alias for sending `lat` and `lng` separately.', required: false, type: 'string', infer: false, example: '3.139,101.6869')]
    #[QueryParameter('search', 'Optional free-text search across public institution names, nicknames, and descriptions.', required: false, type: 'string', infer: false, example: 'Masjid Biru')]
    #[QueryParameter('lat', 'Current device latitude. Provide with `lng` to filter institutions within `radius_km`.', required: false, type: 'number', infer: false, example: 3.139)]
    #[QueryParameter('lng', 'Current device longitude. Provide with `lat` to filter institutions within `radius_km`.', required: false, type: 'number', infer: false, example: 101.6869)]
    #[QueryParameter('radius_km', 'Nearby search radius in kilometers. Values are clamped from 1 to 100 and default to 15 when `lat` and `lng` are present.', required: false, type: 'integer', infer: false, default: 15, example: 15)]
    #[QueryParameter('fields', 'Optional comma-separated top-level list fields to return. Supported fields: id, slug, name, type, nickname, display_name, events_count, public_image_url, logo_url, cover_url, country, location, distance_km, is_following.', required: false, type: 'string', infer: false, example: 'id,name,location')]
    #[QueryParameter('type', 'Optional institution type filter.', required: false, type: 'string', infer: false, example: 'masjid')]
    #[QueryParameter('country_id', 'Optional package address country UUID filter.', required: false, type: 'string', infer: false, example: '019d0000-0000-7000-8000-000000000000')]
    #[QueryParameter('state_id', 'Optional package addressing states.id filter (addresses.state_id).', required: false, type: 'string', infer: false, example: '019d0000-0000-7000-8000-000000000001')]
    #[QueryParameter('city_id', 'Optional package addressing cities.id filter (addresses.city_id).', required: false, type: 'string', infer: false, example: '019d0000-0000-7000-8000-0000000000ab')]
    #[QueryParameter('admin_area_1_id', 'Optional district UUID filter (addresses.admin_area_1_id).', required: false, type: 'string', infer: false, example: '019d0000-0000-7000-8000-000000000001')]
    #[QueryParameter('admin_area_2_id', 'Optional package address area level-2 UUID filter.', required: false, type: 'string', infer: false, example: '019d0000-0000-7000-8000-000000000002')]
    #[QueryParameter('admin_area_3_id', 'Optional country-profile administrative-area UUID filter.', required: false, type: 'string', infer: false)]
    #[QueryParameter('admin_area_4_id', 'Optional country-profile administrative-area UUID filter.', required: false, type: 'string', infer: false)]
    #[QueryParameter('following', 'When authenticated, restrict results to institutions followed by the current user.', required: false, type: 'boolean', infer: false, example: false)]
    #[QueryParameter('page', 'Pagination page number.', required: false, type: 'integer', infer: false, default: 1, example: 1)]
    #[QueryParameter('per_page', 'Pagination page size. Values are clamped to the server-supported maximum.', required: false, type: 'integer', infer: false, default: 12, example: 12)]
    #[Response(
        status: 200,
        description: 'Institution directory response.',
        type: InstitutionDirectoryResponse::class,
    )]
    public function institutions(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $search = $this->searchRequestNormalizer->normalizedString($request->query('search'));
        $requestedFields = $this->searchRequestNormalizer->requestedFields($request, self::INSTITUTION_LIST_FIELDS, 'institution');
        $institutionType = $this->searchRequestNormalizer->normalizedInstitutionType($request->query('type'));
        $countryId = $this->searchRequestNormalizer->requestedCountryId($request);
        $stateId = $this->searchRequestNormalizer->normalizedUuid($request->query('state_id'));
        $cityId = $this->searchRequestNormalizer->normalizedUuid($request->query('city_id'));
        $areaAssignments = is_array($request->query('area_assignments')) ? $request->query('area_assignments') : [];
        $adminArea1Id = $this->searchRequestNormalizer->normalizedUuid($areaAssignments['administrative_district'] ?? null);
        $adminArea2Id = $this->searchRequestNormalizer->normalizedUuid($areaAssignments['administrative_subdivision'] ?? null);
        $adminArea3Id = null;
        $adminArea4Id = null;
        $coordinates = $this->searchRequestNormalizer->resolvedNearbyCoordinates($request);
        $lat = $coordinates['lat'];
        $lng = $coordinates['lng'];
        $radius = $this->searchRequestNormalizer->normalizedRadiusKm($request);
        $hasNearbyLocation = $lat !== null && $lng !== null;
        $perPage = ApiPagination::normalizePerPage($request->integer('per_page', 12), default: 12, max: 50);
        $followingOnly = $request->boolean('following');
        $followingTotal = 0;

        $baseQuery = $this->baseInstitutionQuery(
            type: $institutionType,
            countryId: $countryId,
            stateId: $stateId,
            cityId: $cityId,
            adminArea1Id: $adminArea1Id,
            adminArea2Id: $adminArea2Id,
            adminArea3Id: $adminArea3Id,
            adminArea4Id: $adminArea4Id,
            user: $user,
        );

        if ($hasNearbyLocation) {
            $this->applyInstitutionNearbyScope($baseQuery, $lat, $lng, $radius);
        }

        if ($followingOnly) {
            $this->applyInstitutionFollowingScope($baseQuery, $user);
        } elseif ($user instanceof User) {
            $followedInstitutionQuery = clone $baseQuery;

            $this->applyInstitutionFollowingScope($followedInstitutionQuery, $user);

            $followingTotal = $this->institutionDirectoryTotalWithBase($request, $search, $followedInstitutionQuery);
        }

        $institutions = $search === null
            ? $baseQuery
                ->when(! $hasNearbyLocation, fn (Builder $query) => $query->publicDirectoryOrder())
                ->paginate($perPage)
            : $this->institutionDirectorySearchPaginator($request, $search, $perPage, $baseQuery);

        if ($followingOnly) {
            $followingTotal = $institutions->total();
        }

        return response()->json([
            'data' => collect($institutions->items())
                ->map(fn (Institution $institution): array => $this->searchRequestNormalizer->sparsePayload($this->institutionListData($institution, $user), $requestedFields))
                ->all(),
            'meta' => [
                'pagination' => [
                    'page' => $institutions->currentPage(),
                    'per_page' => $institutions->perPage(),
                    'total' => $institutions->total(),
                ],
                'following' => [
                    'total' => $followingTotal,
                ],
                'location' => [
                    'active' => $hasNearbyLocation,
                    'lat' => $lat,
                    'lng' => $lng,
                    'radius_km' => $hasNearbyLocation ? $radius : null,
                ],
                'types' => $this->institutionTypeFiltersData(),
                'cache' => $this->institutionDirectoryCacheData(),
            ],
        ]);
    }

    #[Group('Institution', 'Public institution directory and detail endpoints.')]
    #[Endpoint(
        title: 'List nearby public institutions',
        description: 'Convenience alias for nearby institution discovery. Accepts `near=lat,lng` or explicit `lat` and `lng`, then returns the same payload shape as the public institutions directory.',
    )]
    #[QueryParameter('near', 'Nearby coordinates in `lat,lng` form. Example: `3.139,101.6869`.', required: false, type: 'string', infer: false, example: '3.139,101.6869')]
    #[QueryParameter('lat', 'Current device latitude. Provide with `lng` if not using `near`.', required: false, type: 'number', infer: false, example: 3.139)]
    #[QueryParameter('lng', 'Current device longitude. Provide with `lat` if not using `near`.', required: false, type: 'number', infer: false, example: 101.6869)]
    #[QueryParameter('radius_km', 'Nearby search radius in kilometers. Values are clamped from 1 to 100 and default to 15.', required: false, type: 'integer', infer: false, default: 15, example: 15)]
    #[QueryParameter('fields', 'Optional comma-separated top-level list fields to return.', required: false, type: 'string', infer: false, example: 'id,name,location,distance_km')]
    #[Response(
        status: 200,
        description: 'Institution directory response.',
        type: InstitutionDirectoryResponse::class,
    )]
    public function institutionsNear(Request $request): JsonResponse
    {
        $coordinates = $this->searchRequestNormalizer->resolvedNearbyCoordinates($request);

        if ($coordinates['lat'] === null || $coordinates['lng'] === null) {
            throw ValidationException::withMessages([
                'near' => 'Provide `near=lat,lng` or both `lat` and `lng` to use the nearby institution endpoint.',
            ]);
        }

        return $this->institutions($request);
    }

    #[Group('Person', 'Public person directory and detail endpoints.')]
    #[Endpoint(
        title: 'List public persons',
        description: 'Returns the public person directory with search, location, gender, and follow-state filters.',
    )]
    #[QueryParameter('admin_area_3_id', 'Optional country-profile administrative-area UUID filter.', required: false, type: 'string', infer: false)]
    #[QueryParameter('admin_area_4_id', 'Optional country-profile administrative-area UUID filter.', required: false, type: 'string', infer: false)]
    #[QueryParameter('fields', 'Optional comma-separated top-level list fields to return. Supported fields: id, slug, name, gender, formatted_name, status, events_count, avatar_url, country, is_following.', required: false, type: 'string', infer: false, example: 'id,name,avatar_url')]
    #[Response(
        status: 200,
        description: 'Person directory response.',
        type: PersonDirectoryResponse::class,
    )]
    public function persons(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $search = $this->searchRequestNormalizer->normalizedString($request->query('search'));
        $requestedFields = $this->searchRequestNormalizer->requestedFields($request, self::PERSON_LIST_FIELDS, 'person');
        $directorySeed = $this->searchRequestNormalizer->normalizedString($request->query('directory_seed'));
        $perPage = ApiPagination::normalizePerPage($request->integer('per_page', 12), default: 12, max: 50);
        $countryId = $this->searchRequestNormalizer->requestedCountryId($request);
        $areaAssignments = is_array($request->query('area_assignments')) ? $request->query('area_assignments') : [];
        $adminArea1Id = $this->searchRequestNormalizer->normalizedUuid($areaAssignments['administrative_district'] ?? null);
        $adminArea2Id = $this->searchRequestNormalizer->normalizedUuid($areaAssignments['administrative_subdivision'] ?? null);
        $gender = in_array($request->query('gender'), ['male', 'female'], true)
            ? $request->query('gender')
            : null;
        $sort = $request->query('sort') === 'upcoming' ? 'upcoming' : null;
        $followingOnly = $request->boolean('following');
        $followingTotal = 0;

        $baseQuery = $this->basePersonQuery($user);

        $stateId = $this->searchRequestNormalizer->normalizedUuid($request->query('state_id'));
        $cityId = $this->searchRequestNormalizer->normalizedUuid($request->query('city_id'));
        $this->applyPersonLocationScope($baseQuery, $countryId, $stateId, $cityId, $adminArea1Id, $adminArea2Id);

        if ($gender !== null) {
            $baseQuery->where('persons.gender', $gender);
        }

        if ($sort === 'upcoming') {
            $baseQuery->orderBy('events_count', 'desc');
        }

        if ($followingOnly) {
            $this->applyPersonFollowingScope($baseQuery, $user);
        } elseif ($user instanceof User) {
            $followedPersonQuery = clone $baseQuery;

            $this->applyPersonFollowingScope($followedPersonQuery, $user);

            $followingTotal = $this->personDirectoryTotalWithBase($request, $search, $followedPersonQuery, $sort);
        }

        $persons = $search === null
            ? $baseQuery->when($sort === null, fn ($q) => $q->publicDirectoryOrder($directorySeed))->paginate($perPage)
            : $this->personDirectorySearchPaginatorWithBase($request, $search, $perPage, $baseQuery, $sort);

        if ($followingOnly) {
            $followingTotal = $persons->total();
        }

        $personDirectoryCache = $this->personDirectoryCacheData();

        return response()->json([
            'data' => collect($persons->items())
                ->map(fn (Person $person): array => $this->searchRequestNormalizer->sparsePayload($this->personListData($person, $user), $requestedFields))
                ->all(),
            'meta' => [
                'pagination' => [
                    'page' => $persons->currentPage(),
                    'per_page' => $persons->perPage(),
                    'total' => $persons->total(),
                ],
                'following' => [
                    'total' => $followingTotal,
                ],
                'cache' => $personDirectoryCache,
            ],
        ]);
    }

    #[Group('Inspiration', 'Public inspiration discovery endpoints for random featured inspiration content.')]
    #[Endpoint(
        title: 'Get a random inspiration',
        description: 'Returns one random active inspiration record, localized when a `locale` query value is provided.',
    )]
    public function randomInspiration(Request $request): JsonResponse
    {
        $locale = $this->searchRequestNormalizer->normalizedString($request->query('locale'));
        $record = Inspiration::query()
            ->with('media')
            ->active()
            ->forLocale($locale)
            ->inRandomOrder()
            ->first();

        return response()->json([
            'data' => $record ? $this->inspirationData($record) : null,
            'meta' => [
                'locale' => $locale ?? app()->getLocale(),
            ],
        ]);
    }

    #[Group('Institution', 'Public institution directory and detail endpoints.')]
    #[Endpoint(
        title: 'Get a public institution',
        description: 'Returns the public institution detail payload by slug or UUID, including upcoming and past events.',
    )]
    #[Response(
        status: 200,
        description: 'Institution detail response.',
        type: InstitutionDetailResponse::class,
    )]
    public function showInstitution(Request $request, string $institutionKey): JsonResponse
    {
        $user = $this->currentUser($request);
        $now = now();
        $record = Institution::query()
            ->with([
                'media',
                'addresses.country',
                'contactMethods',
                'socialProfiles',
                'donationChannels.media',
                'persons' => fn ($query) => $query->where('status', 'verified')->orderByPivot('is_primary', 'desc')->limit(12),
                'persons.media',
                'spaces' => fn ($query) => $query->where('status', 'active'),
                'languages',
            ])
            ->tap(fn (Builder $query): Builder => $this->slugOrUuidResolver->apply($query, 'institutions.slug', $institutionKey))
            ->firstOrFail();

        abort_unless($user instanceof User ? $user->can('view', $record) : $record->status === 'verified', 404);

        $upcomingPerPage = max(1, min($request->integer('upcoming_per_page', 6), 50));
        $upcomingEvents = $this->limitedEventPayloadWithTotal(
            $this->institutionEventsQuery($record)
                ->active()
                ->where('starts_at', '>=', $now)
                ->with(['institution.media', 'venue.addresses.country', 'persons.media', 'keyPeople.person', 'media', 'references'])
                ->orderBy('starts_at'),
            $upcomingPerPage,
        );

        $pastPerPage = max(1, min($request->integer('past_per_page', 6), 50));
        $pastEvents = $this->limitedEventPayloadWithTotal(
            $this->institutionEventsQuery($record)
                ->active()
                ->where('starts_at', '<', $now)
                ->with(['institution.media', 'venue.addresses.country', 'persons.media', 'keyPeople.person', 'media', 'references'])
                ->orderByDesc('starts_at'),
            $pastPerPage,
        );

        return response()->json([
            'data' => [
                'institution' => $this->institutionDetailData($record, $user),
                'upcoming_events' => $upcomingEvents['items'],
                'upcoming_total' => $upcomingEvents['total'],
                'past_events' => $pastEvents['items'],
                'past_total' => $pastEvents['total'],
            ],
        ]);
    }

    #[Group('Person', 'Public person directory and detail endpoints.')]
    #[Endpoint(
        title: 'Get a public person',
        description: 'Returns the public person detail payload by slug or UUID, including person events and other key-person participations.',
    )]
    #[Response(
        status: 200,
        description: 'Person detail response.',
        type: PersonDetailResponse::class,
    )]
    public function showPerson(Request $request, string $personKey): JsonResponse
    {
        $user = $this->currentUser($request);
        $now = now();
        $record = Person::query()
            ->with([
                'media',
                'contactMethods',
                'socialProfiles',
                'addresses.country',
                'institutions' => fn ($query) => $query->orderByPivot('is_primary', 'desc')->limit(3),
                'institutions.media',
            ])
            ->tap(fn (Builder $query): Builder => $this->slugOrUuidResolver->apply($query, 'persons.slug', $personKey))
            ->firstOrFail();

        abort_unless($user instanceof User ? $user->can('view', $record) : ($record->status === 'verified'), 404);

        $otherRoleUpcomingPerPage = max(1, min($request->integer('other_role_upcoming_per_page', 6), 50));
        $otherRoleUpcomingMatches = $record->nonSpeakerEventKeyPeople()
            ->whereHas('event', function (Builder $query) use ($now): void {
                $query
                    ->whereNotNull('events.published_at')
                    ->whereIn('events.status', Event::PUBLIC_STATUSES)
                    ->where('events.visibility', EventVisibility::Public)
                    ->where('starts_at', '>=', $now);
            })
            ->get();

        $otherRoleUpcomingMatches->loadMissing([
            'event.institution',
            'event.institution.media',
            'event.institution.addresses.country',
            'event.venue.addresses.country',
            'event.media',
            'event.references',
        ]);

        $otherRoleUpcomingMatches = $otherRoleUpcomingMatches
            ->sortBy(function (EventKeyPerson $keyPerson): int {
                $event = $keyPerson->event;
                $startsAt = $event instanceof Event ? $event->starts_at : null;

                return $startsAt instanceof \DateTimeInterface ? $startsAt->getTimestamp() : PHP_INT_MAX;
            })
            ->values();
        $otherRoleUpcomingParticipations = $otherRoleUpcomingMatches
            ->take($otherRoleUpcomingPerPage)
            ->values();

        $otherRolePastPerPage = max(1, min($request->integer('other_role_past_per_page', 6), 50));
        $otherRolePastMatches = $record->nonSpeakerEventKeyPeople()
            ->whereHas('event', function (Builder $query) use ($now): void {
                $query
                    ->whereNotNull('events.published_at')
                    ->whereIn('events.status', Event::PUBLIC_STATUSES)
                    ->where('events.visibility', EventVisibility::Public)
                    ->where('starts_at', '<', $now);
            })
            ->get();

        $otherRolePastMatches->loadMissing([
            'event.institution',
            'event.institution.media',
            'event.institution.addresses.country',
            'event.venue.addresses.country',
            'event.media',
            'event.references',
        ]);

        $otherRolePastMatches = $otherRolePastMatches
            ->sortByDesc(function (EventKeyPerson $keyPerson): int {
                $event = $keyPerson->event;
                $startsAt = $event instanceof Event ? $event->starts_at : null;

                return $startsAt instanceof \DateTimeInterface ? $startsAt->getTimestamp() : 0;
            })
            ->values();
        $otherRolePastParticipations = $otherRolePastMatches
            ->take($otherRolePastPerPage)
            ->values();

        $upcomingPerPage = max(1, min($request->integer('upcoming_per_page', 10), 50));
        $upcomingEvents = $this->limitedEventPayloadWithTotal(
            $record->events()
                ->active()
                ->where('starts_at', '>=', $now)
                ->with(['institution', 'institution.media', 'institution.addresses.country', 'venue.addresses.country', 'media', 'references'])
                ->orderBy('starts_at'),
            $upcomingPerPage,
        );

        $pastPerPage = max(1, min($request->integer('past_per_page', 10), 50));
        $pastEvents = $this->limitedEventPayloadWithTotal(
            $record->events()
                ->active()
                ->where('starts_at', '<', $now)
                ->with(['institution', 'institution.media', 'institution.addresses.country', 'venue.addresses.country', 'media', 'references'])
                ->orderByDesc('starts_at'),
            $pastPerPage,
        );

        return response()->json([
            'data' => [
                'person' => $this->personDetailData($record, $user),
                'upcoming_events' => $upcomingEvents['items'],
                'upcoming_total' => $upcomingEvents['total'],
                'past_events' => $pastEvents['items'],
                'past_total' => $pastEvents['total'],
                'other_role_upcoming_participations' => $otherRoleUpcomingParticipations
                    ->map(fn (EventKeyPerson $keyPerson): array => $this->eventParticipationData($keyPerson))
                    ->all(),
                'other_role_upcoming_total' => $otherRoleUpcomingMatches->count(),
                'other_role_past_participations' => $otherRolePastParticipations
                    ->map(fn (EventKeyPerson $keyPerson): array => $this->eventParticipationData($keyPerson))
                    ->all(),
                'other_role_past_total' => $otherRolePastMatches->count(),
            ],
        ]);
    }

    #[Group('Venue', 'Public venue detail endpoints for active verified venues and their related events.')]
    #[Endpoint(
        title: 'Get a public venue',
        description: 'Returns the public venue detail payload by slug or UUID, including upcoming and past events hosted there.',
    )]
    public function showVenue(Request $request, string $venueKey): JsonResponse
    {
        $user = $this->currentUser($request);
        $now = now();
        $canBypassVisibility = $user?->hasAnyRole(['super_admin', 'moderator']) ?? false;

        $record = Venue::query()
            ->with([
                'media',
                'addresses.country',
                'contactMethods',
                'socialProfiles',
            ])
            ->tap(fn (Builder $query): Builder => $this->slugOrUuidResolver->apply($query, 'venues.slug', $venueKey))
            ->firstOrFail();

        if ($record->status !== 'verified' && ! $canBypassVisibility) {
            abort(404);
        }

        $upcomingPerPage = max(1, min($request->integer('upcoming_per_page', 8), 50));
        $upcomingEvents = $this->limitedEventPayloadWithTotal(
            $record->events()
                ->active()
                ->where('starts_at', '>=', $now)
                ->with([
                    'institution.media',
                    'institution.addresses.country',
                    'persons.media',
                    'keyPeople.person.media',
                    'media',
                    'references',
                ])
                ->orderBy('starts_at'),
            $upcomingPerPage,
        );

        $pastPerPage = max(1, min($request->integer('past_per_page', 8), 50));
        $pastEvents = $this->limitedEventPayloadWithTotal(
            $record->events()
                ->active()
                ->where('starts_at', '<', $now)
                ->with([
                    'institution.media',
                    'institution.addresses.country',
                    'persons.media',
                    'keyPeople.person.media',
                    'media',
                    'references',
                ])
                ->orderByDesc('starts_at'),
            $pastPerPage,
        );

        return response()->json([
            'data' => [
                'venue' => $this->venueDetailData($record),
                'upcoming_events' => $upcomingEvents['items'],
                'upcoming_total' => $upcomingEvents['total'],
                'past_events' => $pastEvents['items'],
                'past_total' => $pastEvents['total'],
            ],
        ]);
    }

    #[Group('Reference', 'Public reference directory and detail endpoints.')]
    #[Endpoint(
        title: 'List public references',
        description: 'Returns a paginated directory of active, verified references. Supports search by title, author, or publisher, and a following filter.',
    )]
    #[QueryParameter('fields', 'Optional comma-separated top-level list fields to return. Supported fields: id, slug, title, display_title, author, type, parent_reference_id, part_type, part_number, part_label, is_part, publisher, publication_year, status, events_count, front_cover_url, is_following.', required: false, type: 'string', infer: false, example: 'id,display_title,author,front_cover_url')]
    #[QueryParameter('search', 'Optional free-text search across public reference titles, authors, and publishers.', required: false, type: 'string', infer: false, example: 'Riyadus Solihin')]
    #[QueryParameter('following', 'When authenticated, restrict results to references followed by the current user.', required: false, type: 'boolean', infer: false, example: false)]
    #[QueryParameter('page', 'Pagination page number.', required: false, type: 'integer', infer: false, default: 1, example: 1)]
    #[QueryParameter('per_page', 'Pagination page size. Values are clamped to the server-supported maximum.', required: false, type: 'integer', infer: false, default: 12, example: 12)]
    #[Response(
        status: 200,
        description: 'Reference directory response.',
        type: ReferenceDirectoryResponse::class,
    )]
    public function references(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $search = $this->searchRequestNormalizer->normalizedString($request->query('search'));
        $followingOnly = $request->boolean('following');
        $perPage = ApiPagination::normalizePerPage($request->integer('per_page', 12), default: 12, max: 50);
        $requestedFields = $this->searchRequestNormalizer->requestedFields($request, self::REFERENCE_LIST_FIELDS, 'reference');
        $followingTotal = 0;

        $baseQuery = $this->baseReferenceQuery($user);

        if ($followingOnly) {
            $this->applyReferenceFollowingScope($baseQuery, $user);
        } elseif ($user instanceof User) {
            $followedReferenceQuery = clone $baseQuery;

            $this->applyReferenceFollowingScope($followedReferenceQuery, $user);

            $followingTotal = $this->referenceDirectoryTotalWithBase($request, $search, $followedReferenceQuery);
        }

        $paginator = $search === null
            ? $baseQuery
                ->orderBy('references.title')
                ->paginate($perPage)
            : $this->referenceDirectorySearchPaginator($request, $search, $perPage, $baseQuery);

        if ($followingOnly) {
            $followingTotal = $paginator->total();
        }

        return response()->json([
            'data' => $paginator
                ->getCollection()
                ->map(fn (Reference $reference): array => $this->searchRequestNormalizer->sparsePayload($this->referenceListData($reference, $user), $requestedFields))
                ->all(),
            'meta' => [
                'pagination' => [
                    'page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
                'following' => ['total' => $followingTotal],
            ],
        ]);
    }

    #[Group('Reference', 'Public reference directory and detail endpoints.')]
    #[Endpoint(
        title: 'Get a public reference',
        description: 'Returns the public reference detail payload by slug or UUID, including upcoming and past events linked to that reference.',
    )]
    #[QueryParameter('include_all_parts', 'For a child book part, include events from the whole book family instead of only the exact part.', required: false, type: 'boolean', infer: false, example: false)]
    public function showReference(Request $request, string $referenceKey): JsonResponse
    {
        $user = $this->currentUser($request);
        $now = now();

        $record = Reference::query()
            ->with(['media', 'socialProfiles'])
            ->tap(fn (Builder $query): Builder => $this->slugOrUuidResolver->apply($query, 'references.slug', $referenceKey))
            ->firstOrFail();

        abort_unless($user instanceof User ? $user->can('view', $record) : ($record->status === 'verified'), 404);

        $referenceEventIds = $record->isRootReference() || $request->boolean('include_all_parts')
            ? $record->familyReferenceIds()
            : $record->defaultEventReferenceIds();

        $upcomingPerPage = max(1, min($request->integer('upcoming_per_page', 10), 50));
        $upcomingEvents = $this->limitedEventPayloadWithTotal(
            Event::query()
                ->active()
                ->whereHas('references', function (Builder $referenceQuery) use ($referenceEventIds): void {
                    $referenceQuery->whereIn('references.id', $referenceEventIds);
                })
                ->where('starts_at', '>=', $now)
                ->with([
                    'institution',
                    'institution.media',
                    'institution.addresses.country',
                    'persons.media',
                    'venue.addresses.country',
                    'media',
                ])
                ->orderBy('starts_at', 'asc'),
            $upcomingPerPage,
        );

        $pastPerPage = max(1, min($request->integer('past_per_page', 10), 50));
        $pastEvents = $this->limitedEventPayloadWithTotal(
            Event::query()
                ->active()
                ->whereHas('references', function (Builder $referenceQuery) use ($referenceEventIds): void {
                    $referenceQuery->whereIn('references.id', $referenceEventIds);
                })
                ->where('starts_at', '<', $now)
                ->with([
                    'institution',
                    'institution.media',
                    'institution.addresses.country',
                    'persons.media',
                    'venue.addresses.country',
                    'media',
                ])
                ->orderByDesc('starts_at'),
            $pastPerPage,
        );

        return response()->json([
            'data' => [
                'reference' => $this->referenceDetailData($record, $user),
                'upcoming_events' => $upcomingEvents['items'],
                'upcoming_total' => $upcomingEvents['total'],
                'past_events' => $pastEvents['items'],
                'past_total' => $pastEvents['total'],
            ],
        ]);
    }

    #[Group('Series', 'Public series detail endpoints for visible series and their related events.')]
    #[Endpoint(
        title: 'Get a public series',
        description: 'Returns the public series detail payload by slug or UUID, including upcoming and past events in the series.',
    )]
    public function showSeries(Request $request, string $series): JsonResponse
    {
        $user = $this->currentUser($request);
        $now = now();
        $canBypassVisibility = $user?->hasAnyRole(['super_admin', 'moderator']) ?? false;

        $record = Series::query()
            ->with(['media'])
            ->tap(fn (Builder $query): Builder => $this->slugOrUuidResolver->apply($query, (new Series)->getTable().'.slug', $series))
            ->firstOrFail();

        if ($record->visibility !== 'public' && ! $canBypassVisibility) {
            abort(404);
        }

        $upcomingPerPage = max(1, min($request->integer('upcoming_per_page', 10), 50));
        $upcomingEvents = $this->limitedEventPayloadWithTotal(
            $record->events()
                ->active()
                ->where('starts_at', '>=', $now)
                ->with([
                    'institution',
                    'institution.addresses.country',
                    'venue.addresses.country',
                    'media',
                ])
                ->orderBy('starts_at', 'asc'),
            $upcomingPerPage,
        );

        $pastPerPage = max(1, min($request->integer('past_per_page', 10), 50));
        $pastEvents = $this->limitedEventPayloadWithTotal(
            $record->events()
                ->active()
                ->where('starts_at', '<', $now)
                ->with([
                    'institution',
                    'institution.addresses.country',
                    'venue.addresses.country',
                    'media',
                ])
                ->orderByDesc('starts_at'),
            $pastPerPage,
        );

        return response()->json([
            'data' => [
                'series' => $this->seriesDetailData($record, $user),
                'upcoming_events' => $upcomingEvents['items'],
                'upcoming_total' => $upcomingEvents['total'],
                'past_events' => $pastEvents['items'],
                'past_total' => $pastEvents['total'],
            ],
        ]);
    }

    /**
     * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
     * @template TPivot of Pivot
     *
     * @param  Builder<Event>|HasMany<Event, TDeclaringModel>|BelongsToMany<Event, TDeclaringModel, TPivot, 'pivot'>  $query
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function limitedEventPayloadWithTotal(Builder|HasMany|BelongsToMany $query, int $perPage): array
    {
        /** @var Collection<int, Event> $limitedEvents */
        $limitedEvents = (clone $query)
            ->take($perPage + 1)
            ->get();

        /** @var Collection<int, Event> $visibleEvents */
        $visibleEvents = $limitedEvents
            ->take($perPage)
            ->values();

        $items = $visibleEvents
            ->map(fn (Event $event): array => $this->eventListData($event))
            ->all();

        if ($limitedEvents->count() <= $perPage) {
            return [
                'items' => $items,
                'total' => $visibleEvents->count(),
            ];
        }

        return [
            'items' => $items,
            'total' => (clone $query)->count(),
        ];
    }

    /**
     * @param  list<string>  $institutionIds
     * @return Builder<Institution>
     */
    private function aggregateInstitutionSearchItemsQuery(array $institutionIds = []): Builder
    {
        $query = Institution::query()
            ->select('institutions.*')
            ->active()
            ->where('status', 'verified')
            ->selectSub($this->institutionPublicEventCountSubquery(upcomingOnly: true), 'events_count')
            ->with(['addresses', 'media']);

        if ($institutionIds !== []) {
            $query->whereIn('institutions.id', $institutionIds);
        }

        return $query;
    }

    /**
     * @return Builder<Institution>
     */
    private function baseInstitutionQuery(
        ?InstitutionType $type = null,
        ?string $countryId = null,
        ?string $stateId = null,
        ?string $cityId = null,
        ?string $adminArea1Id = null,
        ?string $adminArea2Id = null,
        ?string $adminArea3Id = null,
        ?string $adminArea4Id = null,
        ?User $user = null,
    ): Builder {
        $query = Institution::query()
            ->select('institutions.*');

        if ($user instanceof User) {
            $query->selectRaw(
                'exists(select 1 from engagement_follows where engagement_follows.follower_id = ? and engagement_follows.followable_id = institutions.id and engagement_follows.followable_type = ? and engagement_follows.follower_type = ? and engagement_follows.status = ?) as is_following',
                [$user->id, (new Institution)->getMorphClass(), (new User)->getMorphClass(), 'active'],
            );
        }

        $query
            ->active()
            ->where('status', 'verified')
            ->selectSub($this->institutionPublicEventCountSubquery(), 'events_count')
            ->with(['addresses', 'media']);

        if ($type instanceof InstitutionType) {
            $query->where('institutions.type', $type->value);
        }

        $this->applyInstitutionLocationScope($query, $countryId, $stateId, $cityId, $adminArea1Id, $adminArea2Id);

        return $query;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function institutionTypeFiltersData(): array
    {
        return array_map(
            static fn (InstitutionType $type): array => [
                'value' => $type->value,
                'label' => $type->getLabel(),
            ],
            InstitutionType::cases(),
        );
    }

    /**
     * @param  Builder<Institution>  $query
     */
    private function applyInstitutionLocationScope(
        Builder $query,
        ?string $countryId,
        ?string $stateId,
        ?string $cityId,
        ?string $adminArea1Id,
        ?string $adminArea2Id,
    ): void {
        $this->addressLocationScope->apply($query, new AddressLocationData(
            countryId: $countryId,
            stateId: $stateId,
            cityId: $cityId,
            areaAssignments: array_filter([
                'administrative_district' => $adminArea1Id,
                'administrative_subdivision' => $adminArea2Id,
            ]),
        ));
    }

    /**
     * @param  Builder<Institution>  $query
     */
    private function applyInstitutionNearbyScope(Builder $query, float $lat, float $lng, int $radiusKm): void
    {
        $addressMorphType = (new Institution)->getMorphClass();
        $addressablesTable = config('addressing.tables.addressables', 'addressables');
        $addressesTable = config('addressing.tables.addresses', 'addresses');
        $distanceSql = '(6371 * acos(cos(radians(?)) * cos(radians(institution_addresses.latitude)) * cos(radians(institution_addresses.longitude) - radians(?)) + sin(radians(?)) * sin(radians(institution_addresses.latitude))))';

        if ($query->getQuery()->columns === null) {
            $query->select('institutions.*');
        }

        $query
            ->join($addressablesTable.' as institution_addressables', function ($join) use ($addressMorphType): void {
                $join->on('institution_addressables.addressable_id', '=', 'institutions.id')
                    ->where('institution_addressables.addressable_type', $addressMorphType)
                    ->where('institution_addressables.is_primary', true);
            })
            ->join($addressesTable.' as institution_addresses', 'institution_addresses.id', '=', 'institution_addressables.address_id')
            ->whereRaw('institution_addresses.latitude is not null')
            ->whereRaw('institution_addresses.longitude is not null')
            ->selectRaw("{$distanceSql} as distance_km", [$lat, $lng, $lat])
            ->whereRaw("{$distanceSql} <= ?", [$lat, $lng, $lat, $radiusKm])
            ->orderBy('distance_km')
            ->orderBy('institutions.name')
            ->orderBy('institutions.id');
    }

    /**
     * @return Builder<Event>
     */
    private function institutionEventsQuery(Institution $institution): Builder
    {
        return Event::query()->where('institution_id', (string) $institution->getKey());
    }

    /**
     * @return Builder<Event>
     */
    private function institutionPublicEventCountSubquery(bool $upcomingOnly = false): Builder
    {
        $query = Event::query()
            ->selectRaw('count(*)')
            ->whereColumn('events.institution_id', 'institutions.id')
            ->whereNotNull('events.published_at')
            ->whereIn('events.status', Event::PUBLIC_STATUSES)
            ->where('events.visibility', EventVisibility::Public);

        if ($upcomingOnly) {
            $query->where('events.starts_at', '>=', now());
        }

        return $query;
    }

    /**
     * @param  Builder<Institution>  $base
     * @return LengthAwarePaginator<int, Institution>
     */
    private function institutionDirectorySearchPaginator(Request $request, string $search, int $perPage, Builder $base): LengthAwarePaginator
    {
        return $this->publicDiscovery->paginate(
            request: $request,
            search: $search,
            perPage: $perPage,
            base: $base,
            directBase: (clone $base)->publicDirectoryOrder(),
            adapter: $this->institutionSearchService,
            idColumn: 'institutions.id',
        );
    }

    /**
     * @param  Builder<Institution>  $base
     */
    private function institutionDirectoryTotalWithBase(Request $request, ?string $search, Builder $base): int
    {
        if ($search === null) {
            return (clone $base)->count();
        }

        return $this->institutionDirectorySearchPaginator($request, $search, 1, $base)->total();
    }

    /**
     * @param  Builder<Institution>  $query
     */
    private function applyInstitutionFollowingScope(Builder $query, ?User $user): void
    {
        if (! $user instanceof User) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereExists(function ($followingQuery) use ($user): void {
            $followingQuery
                ->selectRaw('1')
                ->from('engagement_follows')
                ->where('engagement_follows.follower_id', $user->id)
                ->where('engagement_follows.followable_type', (new Institution)->getMorphClass())
                ->whereColumn('engagement_follows.followable_id', 'institutions.id')
                ->where('engagement_follows.follower_type', (new User)->getMorphClass())
                ->where('engagement_follows.status', 'active');
        });
    }

    /**
     * @return array{version: string}
     */
    private function institutionDirectoryCacheData(): array
    {
        return $this->publicDirectoryCacheVersion->institution();
    }

    /**
     * @return array{version: string}
     */
    private function personDirectoryCacheData(): array
    {
        return $this->publicDirectoryCacheVersion->person();
    }

    /**
     * @return Builder<Person>
     */
    private function basePersonQuery(?User $user = null): Builder
    {
        $query = Person::query();

        if ($user instanceof User) {
            $query->select('persons.*')
                ->selectRaw(
                    'exists(select 1 from engagement_follows where engagement_follows.follower_id = ? and engagement_follows.followable_id = persons.id and engagement_follows.followable_type = ? and engagement_follows.follower_type = ? and engagement_follows.status = ?) as is_following',
                    [$user->id, (new Person)->getMorphClass(), (new User)->getMorphClass(), 'active'],
                );
        }

        return $query->active()
            ->where('status', 'verified')
            ->withCount(['events' => function (Builder $query): void {
                $query
                    ->whereNotNull('events.published_at')
                    ->whereIn('events.status', Event::PUBLIC_STATUSES)
                    ->where('events.visibility', EventVisibility::Public)
                    ->where('events.starts_at', '>=', now());
            }])
            ->with(['media', 'addresses']);
    }

    /**
     * @param  Builder<Person>  $base
     * @return LengthAwarePaginator<int, Person>
     */
    private function personDirectorySearchPaginatorWithBase(Request $request, string $search, int $perPage, Builder $base, ?string $sort): LengthAwarePaginator
    {
        return $this->publicDiscovery->paginate(
            request: $request,
            search: $search,
            perPage: $perPage,
            base: $base,
            directBase: $sort === 'upcoming' ? clone $base : (clone $base)->publicDirectoryOrder(),
            adapter: $this->personSearchService,
            idColumn: 'persons.id',
        );
    }

    /**
     * @param  Builder<Person>  $query
     */
    private function applyPersonLocationScope(
        Builder $query,
        ?string $countryId,
        ?string $stateId,
        ?string $cityId,
        ?string $adminArea1Id,
        ?string $adminArea2Id,
    ): void {
        $this->addressLocationScope->apply($query, new AddressLocationData(
            countryId: $countryId,
            stateId: $stateId,
            cityId: $cityId,
            areaAssignments: array_filter([
                'administrative_district' => $adminArea1Id,
                'administrative_subdivision' => $adminArea2Id,
            ]),
        ));
    }

    /**
     * @param  Builder<Person>  $base
     */
    private function personDirectoryTotalWithBase(Request $request, ?string $search, Builder $base, ?string $sort): int
    {
        if ($search === null) {
            return (clone $base)->count();
        }

        return $this->personDirectorySearchPaginatorWithBase($request, $search, 1, $base, $sort)->total();
    }

    /**
     * @param  Builder<Person>  $query
     */
    private function applyPersonFollowingScope(Builder $query, ?User $user): void
    {
        if (! $user instanceof User) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereExists(function ($followingQuery) use ($user): void {
            $followingQuery
                ->selectRaw('1')
                ->from('engagement_follows')
                ->where('engagement_follows.follower_id', $user->id)
                ->where('engagement_follows.followable_type', (new Person)->getMorphClass())
                ->whereColumn('engagement_follows.followable_id', 'persons.id')
                ->where('engagement_follows.follower_type', (new User)->getMorphClass())
                ->where('engagement_follows.status', 'active');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function venueDetailData(Venue $venue): array
    {
        return VenueDetailData::fromModel(
            venue: $venue,
            contacts: $this->searchPayloadTransformer->contactData($venue->contactMethods),
            socialMedia: $this->searchPayloadTransformer->socialMediaData($venue->socialProfiles),
        )->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function referenceDetailData(Reference $reference, ?User $user): array
    {
        return ReferenceDetailData::fromModel(
            reference: $reference,
            user: $user,
            socialMedia: $this->searchPayloadTransformer->socialMediaData($reference->socialProfiles),
        )->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function referenceListData(Reference $reference, ?User $user = null): array
    {
        return ReferenceListData::fromModel($reference, $user)->toArray();
    }

    /**
     * @return Builder<Reference>
     */
    private function baseReferenceQuery(?User $user = null): Builder
    {
        $query = Reference::query();

        if ($user instanceof User) {
            $referenceIdColumn = $query->getQuery()->getGrammar()->wrap((new Reference)->qualifyColumn('id'));

            $query->select('references.*')
                ->selectRaw(
                    'exists(select 1 from engagement_follows where engagement_follows.follower_id = ? and engagement_follows.followable_id = '.$referenceIdColumn.' and engagement_follows.followable_type = ? and engagement_follows.follower_type = ? and engagement_follows.status = ?) as is_following',
                    [$user->id, (new Reference)->getMorphClass(), (new User)->getMorphClass(), 'active'],
                );
        }

        return $query->active()
            ->where('status', 'verified')
            ->withCount(['events' => function (Builder $query): void {
                $query
                    ->whereNotNull('events.published_at')
                    ->whereIn('events.status', Event::PUBLIC_STATUSES)
                    ->where('events.visibility', EventVisibility::Public);
            }])
            ->with(['media']);
    }

    /**
     * @param  Builder<Reference>  $base
     * @return LengthAwarePaginator<int, Reference>
     */
    private function referenceDirectorySearchPaginator(Request $request, string $search, int $perPage, Builder $base): LengthAwarePaginator
    {
        return $this->publicDiscovery->paginate(
            request: $request,
            search: $search,
            perPage: $perPage,
            base: $base,
            directBase: clone $base,
            adapter: $this->referenceSearchService,
            idColumn: 'references.id',
            preserveDirectEngineOrder: true,
        );
    }

    /**
     * @param  Builder<Reference>  $base
     */
    private function referenceDirectoryTotalWithBase(Request $request, ?string $search, Builder $base): int
    {
        if ($search === null) {
            return (clone $base)->count();
        }

        return $this->referenceDirectorySearchPaginator($request, $search, 1, $base)->total();
    }

    /**
     * @param  Builder<Reference>  $query
     */
    private function applyReferenceFollowingScope(Builder $query, ?User $user): void
    {
        if (! $user instanceof User) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereExists(function ($followingQuery) use ($user): void {
            $followingQuery
                ->selectRaw('1')
                ->from('engagement_follows')
                ->where('engagement_follows.follower_id', $user->id)
                ->where('engagement_follows.followable_type', (new Reference)->getMorphClass())
                ->whereColumn('engagement_follows.followable_id', 'references.id')
                ->where('engagement_follows.follower_type', (new User)->getMorphClass())
                ->where('engagement_follows.status', 'active');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function seriesDetailData(Series $series, ?User $user): array
    {
        return SeriesDetailData::fromModel($series, $user)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function institutionDetailData(Institution $institution, ?User $user): array
    {
        $addressModel = $institution->primaryAddress();
        $institutionMedia = $this->institutionCardMediaData($institution);

        return InstitutionDetailData::fromModel(
            institution: $institution,
            user: $user,
            address: $this->searchPayloadTransformer->addressFilterData($addressModel),
            country: $this->searchPayloadTransformer->countryData($addressModel),
            addressLine: $this->searchPayloadTransformer->addressLocation($addressModel),
            media: $institutionMedia,
            personCount: $this->institutionPersonCount($institution),
            contacts: $this->searchPayloadTransformer->contactData($institution->contactMethods),
            socialMedia: $this->searchPayloadTransformer->socialMediaData($institution->socialProfiles),
            donationChannels: $institution->donationChannels
                ->where('status', 'verified')
                ->sortByDesc('is_default')
                ->map(fn (DonationChannel $channel): array => $this->institutionDonationChannelData($channel))
                ->values()
                ->all(),
        )->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function personDetailData(Person $person, ?User $user): array
    {
        $coverUrl = $person->getFirstMediaUrl('cover', 'banner') ?: $person->getFirstMediaUrl('cover');

        return PersonDetailData::fromModel(
            person: $person,
            user: $user,
            address: $this->searchPayloadTransformer->addressFilterData($person->primaryAddress()),
            country: $this->searchPayloadTransformer->countryData($person->primaryAddress()),
            location: $this->searchPayloadTransformer->addressLocation($person->primaryAddress()),
            media: PersonDetailMediaData::fromModel($person, $coverUrl)->toArray(),
            gallery: $this->personGalleryData($person),
            institutions: $person->institutions
                ->map(fn (Institution $institution): array => $this->personInstitutionData($institution))
                ->all(),
            contacts: $this->searchPayloadTransformer->contactData($person->contactMethods),
            socialMedia: $this->searchPayloadTransformer->socialMediaData($person->socialProfiles),
        )->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function eventListData(Event $event): array
    {
        return EventListData::fromModel($event)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function eventParticipationData(EventKeyPerson $keyPerson): array
    {
        return [
            'id' => $keyPerson->id,
            'role' => (string) $keyPerson->role_code,
            'role_label' => $this->searchPayloadTransformer->keyPersonRoleLabel($keyPerson->role_code),
            'display_name' => $keyPerson->display_name,
            'event' => $keyPerson->event instanceof Event ? $this->eventListData($keyPerson->event) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function institutionListData(Institution $institution, ?User $user = null): array
    {
        return InstitutionListData::fromModel($institution, $user)->toArray();
    }

    /** @return array{public_image_url: string, logo_url: string, cover_url: ?string} */
    private function institutionCardMediaData(Institution $institution): array
    {
        $publicImageUrl = $institution->public_image_url;
        $logoUrl = $institution->public_logo_url;
        $coverUrl = $institution->public_cover_url;
        $logoFallbackUrl = $institution->getFallbackMediaUrl('logo', 'thumb');
        $resolvedLogoUrl = $logoUrl !== ''
            ? $logoUrl
            : ($logoFallbackUrl !== '' ? $logoFallbackUrl : $publicImageUrl);

        return [
            'public_image_url' => $publicImageUrl,
            'logo_url' => $resolvedLogoUrl,
            'cover_url' => $coverUrl !== '' ? $coverUrl : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function personListData(Person $person, ?User $user = null): array
    {
        return PersonListData::fromModel($person, $user)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function inspirationData(Inspiration $inspiration): array
    {
        $category = $inspiration->category;

        if (! $category instanceof InspirationCategory) {
            $category = InspirationCategory::from((string) $category);
        }

        $contentHtml = $inspiration->renderContentHtml();
        $contentText = trim(strip_tags($contentHtml));
        $thumbUrl = $inspiration->getFirstMediaUrl('main', 'thumb') ?: null;
        $fullUrl = $inspiration->getFirstMediaUrl('main') ?: null;

        return [
            'id' => $inspiration->id,
            'locale' => $inspiration->locale,
            'title' => $inspiration->title,
            'content' => $contentText,
            'content_html' => $contentHtml,
            'preview_text' => $inspiration->contentPreviewText(160),
            'source' => $inspiration->source,
            'category' => [
                'value' => $category->value,
                'label' => $category->label(),
                'icon' => $category->icon(),
                'color' => $category->color(),
                'is_comic' => $category === InspirationCategory::IslamicComic,
            ],
            'media' => [
                'thumb_url' => $thumbUrl,
                'full_url' => $fullUrl,
                'has_media' => $thumbUrl !== null,
            ],
        ];
    }

    /** @return array{id: string, name: string, display_name: string, slug: string, position: ?string, is_primary: bool, public_image_url: string, logo_url: string, cover_url: ?string} */
    private function personInstitutionData(Institution $institution): array
    {
        return PersonInstitutionData::fromModel($institution, $this->institutionCardMediaData($institution))->toArray();
    }

    /**
     * @return list<array{id: string, name: string, url: string, thumb_url: string}>
     */
    private function personGalleryData(Person $person): array
    {
        return $person->getMedia('gallery')
            ->map(fn (Media $media): array => PersonGalleryItemData::fromModel($media)->toArray())
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function institutionDonationChannelData(DonationChannel $channel): array
    {
        return InstitutionDonationChannelData::fromModel($channel)->toArray();
    }

    private function institutionPersonCount(Institution $institution): int
    {
        $eventIds = Event::query()
            ->where('institution_id', $institution->id)
            ->whereIn('status', ['verified', 'pending'])
            ->where('starts_at', '>=', now())
            ->select('id');

        return (int) DB::table('event_involvements')
            ->where('role_code', EventKeyPersonRole::Speaker->value)
            ->where('involveable_type', 'person')
            ->whereNotNull('involveable_id')
            ->whereIn('event_id', $eventIds)
            ->distinct('involveable_id')
            ->count();
    }
}
