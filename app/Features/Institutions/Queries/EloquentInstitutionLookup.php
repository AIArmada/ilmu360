<?php

declare(strict_types=1);

namespace App\Features\Institutions\Queries;

use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Institutions\Api\InstitutionSummary;
use App\Features\Institutions\Models\Institution;
use App\Platform\Admin\AdminResourceUrl;
use App\Platform\Models\SlugOrUuidResolver;
use App\Shared\Directory\DirectoryOption;
use App\Shared\Directory\Enums\InstitutionStatus;
use App\Shared\Reports\ReportSubject;
use App\Shared\Reports\ReportSubjectType;
use BackedEnum;

final readonly class EloquentInstitutionLookup implements InstitutionLookup
{
    public function __construct(private SlugOrUuidResolver $resolver) {}

    public function resolveIdentifier(string $identifier): ?string
    {
        $entity = $this->entity($identifier);

        return $entity instanceof Institution ? (string) $entity->getKey() : null;
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
}
