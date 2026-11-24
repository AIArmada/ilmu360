<?php

declare(strict_types=1);

namespace App\Features\Persons\EntryPoints\Http;

use AIArmada\Addressing\Data\AddressLocationData;
use AIArmada\Addressing\Support\AddressLocationScope;
use App\Features\Engagement\Api\EngagementSubjectType;
use App\Features\Engagement\Api\SubjectFollows;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\EventCollections;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Api\InstitutionCards;
use App\Features\Persons\Api\Data\PersonDetailData;
use App\Features\Persons\Api\Data\PersonDetailMediaData;
use App\Features\Persons\Api\Data\PersonGalleryItemData;
use App\Features\Persons\Api\Documentation\Schemas\PersonDetailResponse;
use App\Features\Persons\Api\Documentation\Schemas\PersonDirectoryResponse;
use App\Features\Persons\Api\PersonCards;
use App\Features\Persons\Api\PersonListingCacheVersion;
use App\Features\Persons\Api\PersonSearch;
use App\Features\Persons\Models\Person;
use App\Models\User;
use App\Platform\Api\ApiPagination;
use App\Platform\Api\Frontend\SearchPayloadTransformer;
use App\Platform\Api\Search\SearchRequestNormalizer;
use App\Platform\Http\FrontendController;
use App\Platform\Models\SlugOrUuidResolver;
use App\Platform\Search\PublicDiscovery;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class PersonDirectoryController extends FrontendController
{
    /** @var list<string> */
    private const array PERSON_LIST_FIELDS = [
        'id',
        'slug',
        'name',
        'middle_name',
        'family_name',
        'gender',
        'formatted_name',
        'status',
        'events_count',
        'avatar_url',
        'country',
        'is_following',
    ];

    public function __construct(
        private readonly PersonSearch $personSearch,
        private readonly PersonListingCacheVersion $personListingCacheVersion,
        private readonly SearchRequestNormalizer $searchRequestNormalizer,
        private readonly SlugOrUuidResolver $slugOrUuidResolver,
        private readonly AddressLocationScope $addressLocationScope,
        private readonly PublicDiscovery $publicDiscovery,
        private readonly EventCollections $eventCollections,
        private readonly PersonCards $personCards,
        private readonly InstitutionCards $institutionCards,
        private readonly SubjectFollows $subjectFollows,
        private readonly SearchPayloadTransformer $searchPayloadTransformer,
    ) {}

    #[Group('Person', 'Public person directory and detail endpoints.')]
    #[Endpoint(
        title: 'List public persons',
        description: 'Returns the public person directory with search, location, gender, and follow-state filters.',
    )]
    #[QueryParameter('area_assignments[administrative_district]', 'Optional district UUID filter. Use the area_assignments[administrative_district] syntax.', required: false, type: 'string', infer: false, example: '019d0000-0000-7000-8000-000000000001')]
    #[QueryParameter('area_assignments[administrative_subdivision]', 'Optional subdistrict UUID filter. Use the area_assignments[administrative_subdivision] syntax.', required: false, type: 'string', infer: false, example: '019d0000-0000-7000-8000-000000000002')]
    #[QueryParameter('fields', 'Optional comma-separated top-level list fields to return. Supported fields: id, slug, name, middle_name, family_name, gender, formatted_name, status, events_count, avatar_url, country, is_following.', required: false, type: 'string', infer: false, example: 'id,middle_name,family_name,avatar_url')]
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
        $followingIds = $this->followingIds($user);
        $followingTotal = 0;

        $baseQuery = $this->basePersonQuery();
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
            $this->applyFollowingScope($baseQuery, $followingIds);
        } elseif ($user instanceof User) {
            $followedPersonQuery = clone $baseQuery;

            $this->applyFollowingScope($followedPersonQuery, $followingIds);

            $followingTotal = $this->personDirectoryTotalWithBase($request, $search, $followedPersonQuery, $sort);
        }

        $persons = $search === null
            ? $baseQuery->when($sort === null, fn ($q) => $q->publicDirectoryOrder($directorySeed))->paginate($perPage)
            : $this->personDirectorySearchPaginatorWithBase($request, $search, $perPage, $baseQuery, $sort);

        if ($followingOnly) {
            $followingTotal = $persons->total();
        }

        $cards = $this->personCards->forIds(
            collect($persons->items())
                ->map(static fn (Person $person): string => (string) $person->id)
                ->all(),
            $user instanceof User ? (string) $user->id : null,
        );

        return response()->json([
            'data' => collect($persons->items())
                ->map(fn (Person $person): array => $this->searchRequestNormalizer->sparsePayload(
                    $cards[(string) $person->id] ?? [],
                    $requestedFields,
                ))
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
                'cache' => $this->personListingCacheVersion->current(),
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
            ])
            ->tap(fn (Builder $query): Builder => $this->slugOrUuidResolver->apply($query, 'persons.slug', $personKey))
            ->firstOrFail();

        abort_unless(
            $user instanceof User
                ? $user->can('view', $record)
                : in_array($record->getAttribute('status'), ['verified', 'pending'], true),
            404,
        );

        $otherRoleUpcomingPerPage = max(1, min($request->integer('other_role_upcoming_per_page', 6), 50));
        $otherRoleUpcoming = $this->eventCollections->personParticipations((string) $record->id, past: false, perPage: $otherRoleUpcomingPerPage, now: $now);

        $otherRolePastPerPage = max(1, min($request->integer('other_role_past_per_page', 6), 50));
        $otherRolePast = $this->eventCollections->personParticipations((string) $record->id, past: true, perPage: $otherRolePastPerPage, now: $now);

        $upcomingPerPage = max(1, min($request->integer('upcoming_per_page', 10), 50));
        $upcomingEvents = $this->eventCollections->forPerson((string) $record->id, past: false, perPage: $upcomingPerPage, now: $now);

        $pastPerPage = max(1, min($request->integer('past_per_page', 10), 50));
        $pastEvents = $this->eventCollections->forPerson((string) $record->id, past: true, perPage: $pastPerPage, now: $now);

        return response()->json([
            'data' => [
                'person' => $this->personDetailData($record, $user),
                'upcoming_events' => $upcomingEvents['items'],
                'upcoming_total' => $upcomingEvents['total'],
                'past_events' => $pastEvents['items'],
                'past_total' => $pastEvents['total'],
                'other_role_upcoming_participations' => $otherRoleUpcoming['items'],
                'other_role_upcoming_total' => $otherRoleUpcoming['total'],
                'other_role_past_participations' => $otherRolePast['items'],
                'other_role_past_total' => $otherRolePast['total'],
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function followingIds(?User $user): array
    {
        return $user instanceof User
            ? $this->subjectFollows->followedSubjectIds((string) $user->id, EngagementSubjectType::Person)
            : [];
    }

    /**
     * @param  Builder<Person>  $query
     * @param  list<string>  $followingIds
     */
    private function applyFollowingScope(Builder $query, array $followingIds): void
    {
        if ($followingIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('persons.id', $followingIds);
    }

    /**
     * @return Builder<Person>
     */
    private function basePersonQuery(): Builder
    {
        return Person::query()
            ->active()
            ->withCount(['events' => function (Builder $query): void {
                $query
                    ->whereNotNull('events.published_at')
                    ->whereIn('events.status', Event::PUBLIC_STATUSES)
                    ->where('events.visibility', EventVisibility::Public)
                    ->whereHas('occurrences')
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
            adapter: $this->personSearch,
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
     * @return array<string, mixed>
     */
    private function personDetailData(Person $person, ?User $user): array
    {
        return new PersonDetailData(
            id: (string) $person->id,
            slug: (string) $person->slug,
            name: (string) $person->name,
            middle_name: $person->middle_name,
            family_name: $person->family_name,
            gender: $person->gender instanceof \BackedEnum ? $person->gender->value : null,
            formatted_name: (string) $person->formatted_name,
            bio: $person->bio,
            address: $this->searchPayloadTransformer->addressFilterData($person->primaryAddress()),
            country: $this->searchPayloadTransformer->countryData($person->primaryAddress()),
            location: $this->searchPayloadTransformer->addressLocation($person->primaryAddress()),
            status: (string) $person->status,
            verified_by: $person->getAttribute('verified_by'),
            is_following: $user?->isFollowing($person) ?? false,
            followers_count: $person->followersCount(),
            media: new PersonDetailMediaData(
                avatar_url: (string) $person->public_avatar_url,
                main_url: (string) $person->public_main_url,
                share_image_url: $person->hasMedia('avatar')
                    ? (string) $person->public_avatar_url
                    : (string) $person->default_avatar_url,
            )->toArray(),
            gallery: $this->personGalleryData($person),
            institutions: $this->institutionCards->personAffiliations((string) $person->id),
            contacts: $this->searchPayloadTransformer->contactData($person->contactMethods),
            social_media: $this->searchPayloadTransformer->socialMediaData($person->socialProfiles),
        )->toArray();
    }

    /**
     * @return list<array{id: string, name: string, url: string, thumb_url: string}>
     */
    private function personGalleryData(Person $person): array
    {
        return $person->getMedia('gallery')
            ->map(fn (Media $media): array => new PersonGalleryItemData(
                id: (string) $media->getKey(),
                name: $media->name,
                url: $media->getUrl(),
                thumb_url: $media->getAvailableUrl(['gallery_thumb']) ?: $media->getUrl(),
            )->toArray())
            ->all();
    }
}
