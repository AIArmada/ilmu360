<?php

declare(strict_types=1);

namespace App\Features\Institutions\Queries;

use AIArmada\Addressing\Support\AddressHierarchyFormatter;
use AIArmada\Addressing\Support\AddressingTableResolver;
use AIArmada\Addressing\Support\GeoDistance;
use App\Features\Engagement\Api\EngagementSubjectType;
use App\Features\Engagement\Api\SubjectFollows;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Api\Data\InstitutionListData;
use App\Features\Institutions\Api\Enums\InstitutionType;
use App\Features\Institutions\Api\InstitutionCards;
use App\Features\Institutions\Models\Institution;
use App\Features\Institutions\Models\InstitutionName;
use App\Features\Institutions\Services\InstitutionSearchService;
use App\Features\Persons\Api\PersonLookup;
use App\Platform\Api\Frontend\Data\CountryData;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final readonly class InstitutionCardsQuery implements InstitutionCards
{
    public function __construct(
        private InstitutionSearchService $search,
        private SubjectFollows $subjectFollows,
    ) {}

    /**
     * @param  list<string>  $institutionIds
     * @param  array<string, float|int|string>  $distances
     */
    public function forIds(array $institutionIds, ?string $accountId = null, array $distances = []): array
    {
        $institutionIds = array_values(array_unique(array_filter(
            $institutionIds,
            static fn (string $institutionId): bool => $institutionId !== '',
        )));

        if ($institutionIds === []) {
            return [];
        }

        $followingIds = $accountId !== null
            ? $this->subjectFollows->followedSubjectIds($accountId, EngagementSubjectType::Institution)
            : [];

        return Institution::query()
            ->with(['addresses', 'media', 'names'])
            ->withCount(['events as events_count' => $this->publicEventCountScope(...)])
            ->whereIn('institutions.id', $institutionIds)
            ->get()
            ->mapWithKeys(fn (Institution $institution): array => [
                (string) $institution->id => $this->toArray(
                    $institution,
                    in_array((string) $institution->id, $followingIds, true),
                    $distances[(string) $institution->id] ?? null,
                ),
            ])
            ->all();
    }

    public function forId(string $institutionId, ?string $accountId = null): ?array
    {
        $institution = Institution::query()
            ->with(['addresses', 'media', 'names'])
            ->withCount(['events as events_count' => $this->publicEventCountScope(...)])
            ->whereKey($institutionId)
            ->first();

        if (! $institution instanceof Institution) {
            return null;
        }

        $isFollowing = $accountId !== null
            && $this->subjectFollows->followStateFor($accountId, EngagementSubjectType::Institution, $institutionId)->isFollowing;

        return $this->toArray($institution, $isFollowing);
    }

    public function personAffiliations(string $personId, int $limit = 3): array
    {
        return Institution::query()
            ->select('institutions.*')
            ->join('affiliations', function ($join) use ($personId): void {
                $join->on('affiliations.institution_id', '=', 'institutions.id')
                    ->where('affiliations.affiliatable_type', app(PersonLookup::class)->personMorphType())
                    ->where('affiliations.affiliatable_id', $personId);
            })
            ->with([
                'media',
                'names',
                'persons' => fn (BelongsToMany $query) => $query->whereKey($personId),
            ])
            ->orderByDesc('affiliations.is_primary')
            ->orderBy('institutions.name')
            ->limit(max(1, $limit))
            ->get()
            ->map(function (Institution $institution): array {
                $media = $this->cardMedia($institution);
                $position = data_get($institution->persons->first(), 'pivot.position');
                $isPrimary = data_get($institution->persons->first(), 'pivot.is_primary');

                return [
                    'id' => (string) $institution->id,
                    'name' => (string) $institution->name,
                    'display_name' => (string) $institution->display_name,
                    'slug' => (string) $institution->slug,
                    'position' => is_string($position) && $position !== '' ? $position : null,
                    'is_primary' => (bool) $isPrimary,
                    'public_image_url' => $media['public_image_url'],
                    'logo_url' => $media['logo_url'],
                    'cover_url' => $media['cover_url'],
                ];
            })
            ->values()
            ->all();
    }

    public function forSearch(
        string $query,
        ?float $lat = null,
        ?float $lng = null,
        int $radiusKm = 15,
        int $limit = 4,
    ): array {
        $institutionIds = $this->search->resolvedPublicSearchIds($query);

        if ($institutionIds === []) {
            return ['items' => [], 'total' => 0];
        }

        $base = Institution::query()
            ->select('institutions.*')
            ->active()
            ->withCount(['events as events_count' => $this->publicEventCountScope(...)])
            ->with(['addresses', 'media', 'names'])
            ->whereIn('institutions.id', $institutionIds);

        $hasLocation = $lat !== null && $lng !== null;

        if ($hasLocation) {
            $this->applyNearbyScope($base, $lat, $lng, $radiusKm);
        }

        $total = $hasLocation ? (clone $base)->count() : count($institutionIds);
        $items = $base
            ->orderBy('name')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (Institution $institution): array => $this->toArray($institution, false))
            ->all();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param  Builder<Institution>  $query
     */
    private function applyNearbyScope(Builder $query, float $lat, float $lng, int $radiusKm): void
    {
        $addressMorphType = (new Institution)->getMorphClass();
        $addressablesTable = AddressingTableResolver::resolve('addressables');
        $addressesTable = AddressingTableResolver::resolve('addresses');
        $connection = $query->getConnection();
        $driver = $connection instanceof Connection ? $connection->getDriverName() : null;
        $box = GeoDistance::boundingBox($lat, $lng, (float) $radiusKm);
        $distanceSql = GeoDistance::haversineSql('institution_addresses.latitude', 'institution_addresses.longitude', $driver);
        $distanceBindings = GeoDistance::haversineBindings($lat, $lng);

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
            ->whereBetween('institution_addresses.latitude', [$box['min_lat'], $box['max_lat']])
            ->whereBetween('institution_addresses.longitude', [$box['min_lng'], $box['max_lng']])
            ->selectRaw("{$distanceSql} as distance_km", $distanceBindings)
            ->whereRaw("{$distanceSql} <= ?", [...$distanceBindings, $radiusKm])
            ->orderBy('distance_km')
            ->orderBy('institutions.name')
            ->orderBy('institutions.id');
    }

    /**
     * @param  Builder<Event>  $query
     */
    private function publicEventCountScope(Builder $query): void
    {
        $query
            ->whereNotNull('events.published_at')
            ->whereIn('events.status', Event::PUBLIC_STATUSES)
            ->where('events.visibility', EventVisibility::Public)
            ->whereHas('occurrences');
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Institution $institution, bool $isFollowing, ?float $distanceOverride = null): array
    {
        $attributes = $institution->getAttributes();
        $type = $institution->type;
        $institutionType = $type instanceof InstitutionType
            ? $type->value
            : (is_string($type) ? $type : null);
        $media = $this->cardMedia($institution);
        $location = AddressHierarchyFormatter::format($institution->primaryAddress());
        $distanceKm = $distanceOverride ?? $this->distanceKm($attributes['distance_km'] ?? null);

        return new InstitutionListData(
            id: (string) $institution->id,
            slug: (string) $institution->slug,
            name: (string) $institution->name,
            type: $institutionType,
            primary_nickname: $institution->primaryNickname,
            names: $institution->names->map(fn (InstitutionName $name): array => [
                'name_type' => $name->name_type->value,
                'full_name' => $name->full_name,
                'language_code' => $name->language_code,
                'is_primary' => $name->is_primary,
            ])->values()->all(),
            display_name: (string) $institution->display_name,
            events_count: (int) ($institution->events_count ?? 0),
            public_image_url: $media['public_image_url'],
            logo_url: $media['logo_url'],
            cover_url: $media['cover_url'],
            country: CountryData::fromAddress($institution->primaryAddress())?->toArray(),
            location: $location !== '' ? $location : null,
            distance_km: $distanceKm,
            is_following: $isFollowing,
            verified_by: $institution->getAttribute('verified_by'),
        )->toArray();
    }

    /**
     * @return array{public_image_url: string, logo_url: string, cover_url: ?string}
     */
    private function cardMedia(Institution $institution): array
    {
        $publicImageUrl = (string) $institution->public_image_url;
        $logoUrl = (string) $institution->public_logo_url;
        $coverUrl = (string) $institution->public_cover_url;
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

    private function distanceKm(mixed $distance): ?float
    {
        if (! is_numeric($distance)) {
            return null;
        }

        return round((float) $distance, 2);
    }
}
