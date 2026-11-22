<?php

declare(strict_types=1);

namespace App\Features\Institutions\Queries;

use AIArmada\Addressing\Data\AddressLocationData;
use AIArmada\Addressing\Support\AddressHierarchyFormatter;
use AIArmada\Addressing\Support\AddressLocationScope;
use App\Features\Institutions\Api\Enums\InstitutionStatus;
use App\Features\Institutions\Api\InstitutionDirectoryRecord;
use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Institutions\Api\InstitutionSummary;
use App\Features\Institutions\Models\Institution;
use App\Features\Institutions\Services\InstitutionSearchService;
use App\Features\Reports\Api\ReportSubject;
use App\Features\Reports\Api\ReportSubjectType;
use App\Platform\Admin\AdminResourceUrl;
use App\Platform\Models\SlugOrUuidResolver;
use App\Shared\Directory\DirectoryOption;
use App\Shared\Directory\FollowableSubject;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class EloquentInstitutionLookup implements InstitutionLookup
{
    public function __construct(private SlugOrUuidResolver $resolver) {}

    /**
     * Resolved lazily so directory/follow reads do not construct the search
     * services (and their caches) unless a search-specific method is called.
     */
    private function search(): InstitutionSearchService
    {
        return app(InstitutionSearchService::class);
    }

    public function shareIdentityBySlug(string $slug): ?array
    {
        $institution = Institution::query()->where('slug', $slug)->first(['id', 'slug', 'name']);

        if (! $institution instanceof Institution) {
            return null;
        }

        return [
            'id' => (string) $institution->getKey(),
            'slug' => (string) $institution->slug,
            'title' => (string) $institution->name,
        ];
    }

    public function contributionResponseById(string $institutionId): ?array
    {
        $institution = Institution::query()->find($institutionId, ['id', 'slug', 'name', 'status']);

        if (! $institution instanceof Institution) {
            return null;
        }

        $status = $institution->status;

        return [
            'id' => (string) $institution->getKey(),
            'slug' => (string) $institution->slug,
            'name' => (string) $institution->name,
            'status' => $status instanceof BackedEnum ? (string) $status->value : (string) $status,
        ];
    }

    public function findMemberInstitution(string $institutionId, string $accountId): ?InstitutionDirectoryRecord
    {
        $institution = Institution::query()
            ->whereKey($institutionId)
            ->whereHas('members', fn (Builder $query): Builder => $query->whereKey($accountId))
            ->first();

        return $institution instanceof Institution ? $this->directoryRecord($institution) : null;
    }

    public function institutionsByIds(array $institutionIds): array
    {
        return Institution::query()
            ->whereKey($institutionIds)
            ->orderBy('name')
            ->get()
            ->map(fn (Institution $institution): InstitutionDirectoryRecord => $this->directoryRecord($institution))
            ->all();
    }

    public function institutionsForMember(string $accountId): array
    {
        return Institution::query()
            ->whereHas('members', fn (Builder $query): Builder => $query->whereKey($accountId))
            ->withCount('members')
            ->orderBy('name')
            ->get()
            ->map(fn (Institution $institution): InstitutionDirectoryRecord => $this->directoryRecord($institution))
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function visibleDisplayNameOptions(): array
    {
        return Institution::query()
            ->whereIn('status', InstitutionStatus::publiclyVisibleValues())
            ->orderBy('name')
            ->with('names')
            ->get(['id', 'name'])
            ->mapWithKeys(static fn (Institution $institution): array => [
                (string) $institution->id => (string) $institution->display_name,
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function searchDisplayNameOptions(string $search, int $limit = 50): array
    {
        $search = trim($search);
        $searchPattern = "%{$search}%";

        return Institution::query()
            ->whereIn('status', ['verified', 'pending'])
            ->where(function (Builder $query) use ($searchPattern): void {
                $query->whereLike('name', $searchPattern)
                    ->orWhereHas('names', fn (Builder $names): Builder => $names->whereLike('full_name', $searchPattern));
            })
            ->with('names:id,institution_id,full_name,is_primary')
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name'])
            ->mapWithKeys(static fn (Institution $institution): array => [
                (string) $institution->getKey() => (string) $institution->display_name,
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function membershipApplicationOptions(string $search = ''): array
    {
        $query = Institution::query()
            ->where('status', InstitutionStatus::Verified->value)
            ->whereIn('status', InstitutionStatus::publiclyVisibleValues());

        if (filled($search)) {
            $query->searchNameOrNickname($search);
        }

        return $query
            ->orderBy('name')
            ->limit(50)
            ->with(['addresses', 'names'])
            ->get(['id', 'slug', 'name'])
            ->mapWithKeys(fn (Institution $institution): array => [
                (string) $institution->getKey() => $this->membershipApplicationLabelFor($institution),
            ])
            ->all();
    }

    public function membershipApplicationLabel(string $institutionId): ?string
    {
        $institution = Institution::query()
            ->where('status', InstitutionStatus::Verified->value)
            ->whereIn('status', InstitutionStatus::publiclyVisibleValues())
            ->whereKey($institutionId)
            ->with(['addresses', 'names'])
            ->first(['id', 'name']);

        return $institution instanceof Institution
            ? $this->membershipApplicationLabelFor($institution)
            : null;
    }

    public function followedInstitutions(array $institutionIds): array
    {
        return Institution::query()
            ->whereKey($institutionIds)
            ->orderBy('name')
            ->get()
            ->map(fn (Institution $institution): InstitutionDirectoryRecord => $this->directoryRecord($institution))
            ->all();
    }

    private function directoryRecord(Institution $institution): InstitutionDirectoryRecord
    {
        $status = $institution->getAttribute('status');

        return new InstitutionDirectoryRecord(
            id: (string) $institution->getKey(),
            name: (string) $institution->name,
            slug: is_string($institution->slug) ? $institution->slug : null,
            status: $status instanceof BackedEnum ? (string) $status->value : (is_string($status) ? $status : null),
            description: (string) ($institution->getAttributes()['description'] ?? ''),
            memberCount: (int) ($institution->getAttributes()['members_count'] ?? 0),
        );
    }

    private function membershipApplicationLabelFor(Institution $institution): string
    {
        $location = AddressHierarchyFormatter::format($institution->primaryAddress());

        if ($location === '') {
            return (string) $institution->display_name;
        }

        return "{$institution->display_name} - {$location}";
    }

    /**
     * @param  list<string>  $institutionIds
     * @return array<string, string>
     */
    public function displayNamesByIds(array $institutionIds): array
    {
        return Institution::query()
            ->whereKey($institutionIds)
            ->with('names:id,institution_id,full_name,is_primary')
            ->get(['id', 'name'])
            ->mapWithKeys(static fn (Institution $institution): array => [
                (string) $institution->getKey() => (string) $institution->display_name,
            ])
            ->all();
    }

    public function resolveIdentifier(string $identifier): ?string
    {
        $entity = $this->entity($identifier);

        return $entity instanceof Institution ? (string) $entity->getKey() : null;
    }

    public function findFollowableSubject(string $identifier): ?FollowableSubject
    {
        $institution = $this->entity($identifier);

        if (! $institution instanceof Institution) {
            return null;
        }

        return new FollowableSubject(
            id: (string) $institution->getKey(),
            slug: (string) $institution->slug,
            type: 'institution',
            morphType: $institution->getMorphClass(),
            isPubliclyVisible: in_array($institution->status, InstitutionStatus::publiclyVisible(), true),
        );
    }

    public function findDirectoryFollowableSubject(string $identifier): ?FollowableSubject
    {
        $institution = $this->entity($identifier);

        if (! $institution instanceof Institution
            || ! in_array($institution->status, InstitutionStatus::publiclyVisible(), true)) {
            return null;
        }

        return new FollowableSubject(
            id: (string) $institution->getKey(),
            slug: (string) $institution->slug,
            type: 'institution',
            morphType: $institution->getMorphClass(),
            isPubliclyVisible: true,
        );
    }

    /**
     * @return array<string, string>
     */
    public function allNameOptions(): array
    {
        return Institution::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(static fn (mixed $name, mixed $id): array => [(string) $id => (string) $name])
            ->all();
    }

    public function primaryAddressId(string $institutionId): ?string
    {
        $address = Institution::query()->find($institutionId)?->primaryAddress();

        return $address !== null ? (string) $address->getKey() : null;
    }

    /**
     * @return array{found: bool, capacity: int|null}
     */
    public function institutionSpaceCapacity(string $institutionId, string $venueSpaceId): array
    {
        $space = Institution::query()
            ->find($institutionId)
            ?->spaces()
            ->whereKey($venueSpaceId)
            ->first();

        return [
            'found' => $space !== null,
            'capacity' => $space?->effectiveCapacity(),
        ];
    }

    public function find(string $identifier): ?InstitutionSummary
    {
        $entity = $this->entity($identifier);
        if (! $entity instanceof Institution) {
            return null;
        }

        $fallback = (string) $entity->getKey();
        $status = $entity->getAttribute('status');
        $status = $status instanceof BackedEnum ? (string) $status->value : (filled($status) ? (string) $status : null);
        $kind = $entity->getAttribute('type');
        $kind = $kind instanceof BackedEnum ? (string) $kind->value : (is_string($kind) && $kind !== '' ? $kind : null);
        $slug = $entity->getAttribute('slug');
        $image = $entity->hasMedia('logo') ? $entity->getFirstMediaUrl('logo', 'thumb') : null;

        return new InstitutionSummary(
            id: $fallback,
            title: filled($entity->display_name) ? (string) $entity->display_name : $fallback,
            slug: filled($slug) ? (string) $slug : null,
            status: $status,
            publicUrl: route('institutions.show', $entity),
            adminUrl: AdminResourceUrl::edit('institutions', (string) $entity->getRouteKey()),
            imageUrl: is_string($image) && $image !== '' ? $image : null,
            institutionType: $kind,
        );
    }

    public function searchPrayerInstitutions(string $search, int $limit = 50): array
    {
        $query = Institution::query()->where('institutions.status', InstitutionStatus::Verified->value);
        $search = trim($search);
        if ($search !== '') {
            $query->searchNameOrNickname($search);
        }

        return $query->orderBy('name')->limit(max(1, $limit))->with('names')
            ->get(['id', 'name'])
            ->map(static fn (Institution $institution): DirectoryOption => new DirectoryOption(
                id: (string) $institution->id,
                label: $institution->display_name,
            ))->all();
    }

    /**
     * @param  list<string>  $institutionIds
     * @return array<string, string>
     */
    public function prayerInstitutionLabels(array $institutionIds): array
    {
        if ($institutionIds === []) {
            return [];
        }

        $labels = Institution::query()->whereKey($institutionIds)->with('names')->get(['id', 'name'])
            ->mapWithKeys(static fn (Institution $institution): array => [
                (string) $institution->id => $institution->display_name,
            ])->all();
        foreach ($institutionIds as $institutionId) {
            $labels[$institutionId] ??= $institutionId;
        }

        return $labels;
    }

    /**
     * @param  list<string>  $institutionIds
     * @return list<string>
     */
    public function selectablePrayerInstitutionIds(array $institutionIds): array
    {
        if ($institutionIds === []) {
            return [];
        }

        return Institution::query()->where('institutions.status', InstitutionStatus::Verified->value)
            ->whereKey($institutionIds)->pluck('institutions.id')
            ->map(static fn (mixed $id): string => (string) $id)->values()->all();
    }

    /**
     * @param  list<string>  $institutionIds
     * @return array<string, string|null>
     */
    public function rawNamesByIds(array $institutionIds): array
    {
        if ($institutionIds === []) {
            return [];
        }

        return Institution::query()->whereKey($institutionIds)->get(['id', 'name'])
            ->mapWithKeys(static fn (Institution $institution): array => [
                (string) $institution->id => $institution->getRawOriginal('name'),
            ])->all();
    }

    public function findReportSubject(string $id): ?ReportSubject
    {
        if (trim($id) === '') {
            return null;
        }
        $entity = Institution::query()->find($id);

        return $entity instanceof Institution ? $this->reportSubject($entity) : null;
    }

    public function resolveReportSubject(string $identifier): ?ReportSubject
    {
        $entity = $this->entity($identifier);

        return $entity instanceof Institution ? $this->reportSubject($entity) : null;
    }

    private function entity(string $identifier): ?Institution
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        return $this->resolver->first(Institution::query(), 'institutions.slug', $identifier);
    }

    private function reportSubject(Institution $entity): ReportSubject
    {
        $id = (string) $entity->getKey();

        return new ReportSubject(
            type: ReportSubjectType::Institution,
            id: $id,
            label: __('Institution'),
            title: filled($entity->getAttribute('name')) ? (string) $entity->getAttribute('name') : $id,
            slug: (string) $entity->getAttribute('slug'),
            publicUrl: route('institutions.show', $entity),
            adminUrl: AdminResourceUrl::edit('institutions', (string) $entity->getRouteKey()),
            imageUrl: null,
        );
    }

    public function publicSearchIds(string $search): array
    {
        return $this->search()->publicSearchIds($search);
    }

    public function institutionMorphType(): string
    {
        return (new Institution)->getMorphClass();
    }

    public function exists(string $institutionId): bool
    {
        return Institution::query()->whereKey($institutionId)->exists();
    }

    public function assertExists(string $institutionId): void
    {
        if (! $this->exists($institutionId)) {
            throw (new ModelNotFoundException)->setModel(Institution::class, [$institutionId]);
        }
    }

    public function visibleDisplayName(string $institutionId): ?string
    {
        return Institution::query()
            ->whereIn('institutions.status', InstitutionStatus::publiclyVisibleValues())
            ->whereKey($institutionId)
            ->with('names')
            ->first(['id', 'name'])
            ?->display_name;
    }

    public function displayName(string $institutionId): ?string
    {
        return Institution::query()
            ->whereKey($institutionId)
            ->with('names')
            ->first(['id', 'name'])
            ?->display_name;
    }

    /**
     * @param  array<string, string>  $areaAssignments
     * @return array<string, string>
     */
    public function locationOptions(
        ?string $countryId,
        ?string $stateId,
        array $areaAssignments,
        string $search = '',
        int $limit = 50,
    ): array {
        $query = Institution::query()
            ->whereIn('institutions.status', InstitutionStatus::publiclyVisibleValues());

        app(AddressLocationScope::class)->apply($query, new AddressLocationData(
            countryId: filled($countryId) ? $countryId : null,
            stateId: filled($stateId) ? $stateId : null,
            areaAssignments: $areaAssignments,
        ));

        $query->searchNameOrNickname($search);

        return $query
            ->orderBy('name')
            ->with('names')
            ->limit(max(1, $limit))
            ->get(['id', 'name'])
            ->mapWithKeys(static fn (Institution $institution): array => [
                (string) $institution->id => $institution->display_name,
            ])
            ->all();
    }

    /**
     * @param  array<string, string>  $areaAssignments
     */
    public function locationContains(
        string $institutionId,
        ?string $countryId,
        ?string $stateId,
        array $areaAssignments,
    ): bool {
        $query = Institution::query()
            ->whereKey($institutionId)
            ->whereIn('institutions.status', InstitutionStatus::publiclyVisibleValues());

        app(AddressLocationScope::class)->apply($query, new AddressLocationData(
            countryId: filled($countryId) ? $countryId : null,
            stateId: filled($stateId) ? $stateId : null,
            areaAssignments: $areaAssignments,
        ));

        return $query->exists();
    }

    /**
     * @return array<string, string>
     */
    public function nameOptions(string $search = '', int $limit = 50): array
    {
        $query = Institution::query();
        $normalizedSearch = trim($search);

        if ($normalizedSearch !== '') {
            $query->where('name', 'like', '%'.$normalizedSearch.'%');
        }

        return $query
            ->orderBy('name')
            ->limit(max(1, $limit))
            ->pluck('name', 'id')
            ->mapWithKeys(static fn (mixed $name, mixed $id): array => [(string) $id => (string) $name])
            ->all();
    }

    /**
     * @param  list<string>  $institutionIds
     * @return array<string, string>
     */
    public function namesByIds(array $institutionIds): array
    {
        if ($institutionIds === []) {
            return [];
        }

        return Institution::query()
            ->whereKey($institutionIds)
            ->pluck('name', 'id')
            ->mapWithKeys(static fn (mixed $name, mixed $id): array => [(string) $id => (string) $name])
            ->all();
    }
}
