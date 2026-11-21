<?php

declare(strict_types=1);

namespace App\Features\Institutions\EntryPoints\Http;

use AIArmada\Addressing\Data\AddressLocationData;
use AIArmada\Addressing\Support\AddressLocationScope;
use App\Features\DonationChannels\Api\Enums\DonationChannelStatus;
use App\Features\Engagement\Api\EngagementSubjectType;
use App\Features\Engagement\Api\SubjectFollows;
use App\Features\Events\Api\EventCollections;
use App\Features\Events\Api\EventScheduleReads;
use App\Features\Institutions\Api\Data\InstitutionDetailData;
use App\Features\Institutions\Api\Data\InstitutionDonationChannelData;
use App\Features\Institutions\Api\Documentation\Schemas\InstitutionDetailResponse;
use App\Features\Institutions\Api\Documentation\Schemas\InstitutionDirectoryResponse;
use App\Features\Institutions\Api\Enums\InstitutionStatus;
use App\Features\Institutions\Api\Enums\InstitutionType;
use App\Features\Institutions\Api\InstitutionCards;
use App\Features\Institutions\Api\InstitutionListingCacheVersion;
use App\Features\Institutions\Api\InstitutionSearch;
use App\Features\Institutions\Models\Institution;
use App\Features\Institutions\Models\InstitutionName;
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
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

final class InstitutionDirectoryController extends FrontendController
{
    /** @var list<string> */
    private const array INSTITUTION_LIST_FIELDS = [
        'id',
        'slug',
        'name',
        'middle_name',
        'family_name',
        'type',
        'names',
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

    public function __construct(
        private readonly InstitutionSearch $institutionSearch,
        private readonly InstitutionListingCacheVersion $institutionListingCacheVersion,
        private readonly SearchRequestNormalizer $searchRequestNormalizer,
        private readonly SlugOrUuidResolver $slugOrUuidResolver,
        private readonly AddressLocationScope $addressLocationScope,
        private readonly PublicDiscovery $publicDiscovery,
        private readonly EventCollections $eventCollections,
        private readonly EventScheduleReads $eventScheduleQuery,
        private readonly InstitutionCards $institutionCards,
        private readonly SubjectFollows $subjectFollows,
        private readonly SearchPayloadTransformer $searchPayloadTransformer,
    ) {}

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
    #[QueryParameter('fields', 'Optional comma-separated top-level list fields to return. Supported fields: id, slug, name, type, names, display_name, events_count, public_image_url, logo_url, cover_url, country, location, distance_km, is_following.', required: false, type: 'string', infer: false, example: 'id,name,location')]
    #[QueryParameter('type', 'Optional institution type filter.', required: false, type: 'string', infer: false, example: 'masjid')]
    #[QueryParameter('country_id', 'Optional package address country UUID filter.', required: false, type: 'string', infer: false, example: '019d0000-0000-7000-8000-000000000000')]
    #[QueryParameter('state_id', 'Optional package addressing states.id filter (addresses.state_id).', required: false, type: 'string', infer: false, example: '019d0000-0000-7000-8000-000000000001')]
    #[QueryParameter('city_id', 'Optional package addressing cities.id filter (addresses.city_id).', required: false, type: 'string', infer: false, example: '019d0000-0000-7000-8000-0000000000ab')]
    #[QueryParameter('area_assignments[administrative_district]', 'Optional district UUID filter. Use the area_assignments[administrative_district] syntax.', required: false, type: 'string', infer: false, example: '019d0000-0000-7000-8000-000000000001')]
    #[QueryParameter('area_assignments[administrative_subdivision]', 'Optional subdistrict UUID filter. Use the area_assignments[administrative_subdivision] syntax.', required: false, type: 'string', infer: false, example: '019d0000-0000-7000-8000-000000000002')]
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
        $normalizedType = $this->searchRequestNormalizer->normalizedString($request->query('type'));
        $institutionType = $normalizedType !== null ? InstitutionType::tryFrom($normalizedType) : null;
        $countryId = $this->searchRequestNormalizer->requestedCountryId($request);
        $stateId = $this->searchRequestNormalizer->normalizedUuid($request->query('state_id'));
        $cityId = $this->searchRequestNormalizer->normalizedUuid($request->query('city_id'));
        $areaAssignments = is_array($request->query('area_assignments')) ? $request->query('area_assignments') : [];
        $adminArea1Id = $this->searchRequestNormalizer->normalizedUuid($areaAssignments['administrative_district'] ?? null);
        $adminArea2Id = $this->searchRequestNormalizer->normalizedUuid($areaAssignments['administrative_subdivision'] ?? null);
        $coordinates = $this->searchRequestNormalizer->resolvedNearbyCoordinates($request);
        $lat = $coordinates['lat'];
        $lng = $coordinates['lng'];
        $radius = $this->searchRequestNormalizer->normalizedRadiusKm($request);
        $hasNearbyLocation = $lat !== null && $lng !== null;
        $perPage = ApiPagination::normalizePerPage($request->integer('per_page', 12), default: 12, max: 50);
        $followingOnly = $request->boolean('following');
        $followingIds = $this->followingIds($user);
        $followingTotal = 0;

        $baseQuery = $this->baseInstitutionQuery(
            type: $institutionType,
            countryId: $countryId,
            stateId: $stateId,
            cityId: $cityId,
            adminArea1Id: $adminArea1Id,
            adminArea2Id: $adminArea2Id,
        );

        if ($hasNearbyLocation) {
            $this->applyInstitutionNearbyScope($baseQuery, $lat, $lng, $radius);
        }

        if ($followingOnly) {
            $this->applyFollowingScope($baseQuery, $followingIds);
        } elseif ($user instanceof User) {
            $followedInstitutionQuery = clone $baseQuery;

            $this->applyFollowingScope($followedInstitutionQuery, $followingIds);

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

        $cards = $this->institutionCards->forIds(
            collect($institutions->items())
                ->map(static fn (Institution $institution): string => (string) $institution->id)
                ->all(),
            $user instanceof User ? (string) $user->id : null,
            collect($institutions->items())
                ->mapWithKeys(static fn (Institution $institution): array => [
                    (string) $institution->id => $institution->getAttributes()['distance_km'] ?? null,
                ])
                ->filter(static fn (mixed $distance): bool => is_numeric($distance))
                ->all(),
        );

        return response()->json([
            'data' => collect($institutions->items())
                ->map(fn (Institution $institution): array => $this->searchRequestNormalizer->sparsePayload(
                    $cards[(string) $institution->id] ?? [],
                    $requestedFields,
                ))
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
                'cache' => $this->institutionListingCacheVersion->current(),
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
                'names',
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

        abort_unless(
            $user instanceof User
                ? $user->can('view', $record)
                : in_array($record->status, InstitutionStatus::publiclyVisible(), true),
            404,
        );

        $upcomingPerPage = max(1, min($request->integer('upcoming_per_page', 6), 50));
        $upcomingEvents = $this->eventCollections->forInstitution((string) $record->id, past: false, perPage: $upcomingPerPage, now: $now);

        $pastPerPage = max(1, min($request->integer('past_per_page', 6), 50));
        $pastEvents = $this->eventCollections->forInstitution((string) $record->id, past: true, perPage: $pastPerPage, now: $now);

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

    /**
     * @return list<string>
     */
    private function followingIds(?User $user): array
    {
        return $user instanceof User
            ? $this->subjectFollows->followedSubjectIds((string) $user->id, EngagementSubjectType::Institution)
            : [];
    }

    /**
     * @param  Builder<Institution>  $query
     * @param  list<string>  $followingIds
     */
    private function applyFollowingScope(Builder $query, array $followingIds): void
    {
        if ($followingIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('institutions.id', $followingIds);
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
    ): Builder {
        $query = Institution::query()
            ->select('institutions.*')
            ->active()
            ->with(['addresses', 'media', 'names']);

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
        $addressablesTable = \AIArmada\Addressing\Support\AddressingTableResolver::resolve('addressables');
        $addressesTable = \AIArmada\Addressing\Support\AddressingTableResolver::resolve('addresses');
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
            adapter: $this->institutionSearch,
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
     * @return array<string, mixed>
     */
    private function institutionDetailData(Institution $institution, ?User $user): array
    {
        $addressModel = $institution->primaryAddress();
        $institutionMedia = $this->institutionCardMediaData($institution);
        $type = $institution->type;
        $institutionType = $type instanceof InstitutionType
            ? $type->value
            : (is_string($type) ? $type : null);

        return new InstitutionDetailData(
            id: (string) $institution->id,
            slug: (string) $institution->slug,
            name: (string) $institution->name,
            primary_nickname: $institution->primaryNickname,
            names: $institution->names->map(fn (InstitutionName $name): array => [
                'name_type' => $name->name_type->value,
                'full_name' => $name->full_name,
                'language_code' => $name->language_code,
                'is_primary' => $name->is_primary,
            ])->values()->all(),
            display_name: (string) $institution->display_name,
            description: $institution->description,
            status: $institution->status->value,
            verified_by: $institution->getAttribute('verified_by'),
            type: $institutionType,
            type_label: $institution->type instanceof HasLabel ? $institution->type->getLabel() : null,
            address_line: $this->searchPayloadTransformer->addressLocation($addressModel),
            address: $this->searchPayloadTransformer->addressFilterData($addressModel),
            country: $this->searchPayloadTransformer->countryData($addressModel),
            map_url: $addressModel?->google_maps_url,
            map_lat: $addressModel?->latitude,
            map_lng: $addressModel?->longitude,
            followers_count: $institution->followersCount(),
            person_count: $this->eventScheduleQuery->institutionSpeakerCount((string) $institution->id),
            is_following: $user?->isFollowing($institution) ?? false,
            media: $institutionMedia,
            contacts: $this->searchPayloadTransformer->contactData($institution->contactMethods),
            social_media: $this->searchPayloadTransformer->socialMediaData($institution->socialProfiles),
            waze_url: $addressModel?->waze_url,
            donation_channels: $institution->donationChannels
                ->where('status', DonationChannelStatus::Verified)
                ->sortByDesc('is_default')
                ->map(fn ($channel): array => $this->institutionDonationChannelData($channel))
                ->values()
                ->all(),
        )->toArray();
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
    private function institutionDonationChannelData(object $channel): array
    {
        return new InstitutionDonationChannelData(
            id: (string) $channel->id,
            label: (string) $channel->label,
            method: (string) $channel->method,
            method_display: (string) $channel->method_display,
            recipient: (string) $channel->recipient,
            payment_details: (string) $channel->payment_details,
            bank_name: $channel->bank_name,
            bank_code: $channel->bank_code,
            account_number: $channel->account_number,
            duitnow_type: $channel->duitnow_type,
            duitnow_value: $channel->duitnow_value,
            ewallet_provider: $channel->ewallet_provider,
            ewallet_handle: $channel->ewallet_handle,
            is_default: (bool) $channel->is_default,
            qr_url: $channel->getFirstMediaUrl('qr', 'thumb') ?: $channel->getFirstMediaUrl('qr') ?: null,
            qr_full_url: $channel->getFirstMediaUrl('qr') ?: null,
        )->toArray();
    }
}
