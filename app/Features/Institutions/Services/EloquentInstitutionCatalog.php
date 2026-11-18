<?php

declare(strict_types=1);

namespace App\Features\Institutions\Services;

use AIArmada\Membership\Enums\MemberRole;
use App\Features\Institutions\Api\InstitutionCatalog;
use App\Features\Institutions\Models\Institution;
use App\Shared\Directory\Enums\InstitutionStatus;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentInstitutionCatalog implements InstitutionCatalog
{
    public function __construct(private InstitutionSearchService $institutionSearchService) {}

    public function membershipApplicationSubjects(string $search): array
    {
        $query = Institution::query()
            ->where('status', InstitutionStatus::Verified->value)
            ->whereIn('status', InstitutionStatus::publiclyVisibleValues());

        $this->applySearch($query, $search);

        return $query
            ->orderBy('name')
            ->with('names')
            ->limit(50)
            ->get(['id', 'slug', 'name'])
            ->map(fn (Institution $institution): array => [
                'id' => (string) $institution->id,
                'slug' => (string) $institution->slug,
                'label' => $institution->display_name,
            ])
            ->all();
    }

    public function prayerOptions(string $search): array
    {
        $query = Institution::query()
            ->active()
            ->where('status', InstitutionStatus::Verified->value)
            ->orderBy('name');

        $this->applySearch($query, $search);

        return $query
            ->with('names')
            ->limit(50)
            ->get(['id', 'name'])
            ->map(fn (Institution $institution): array => [
                'id' => (string) $institution->id,
                'label' => $institution->display_name,
            ])
            ->all();
    }

    public function roleOptions(): array
    {
        return collect(MemberRole::cases())
            ->mapWithKeys(fn (MemberRole $role): array => [$role->value => $role->label()])
            ->all();
    }

    public function submissionOptions(?string $accountId, ?string $search = null, int $limit = 50): array
    {
        $query = Institution::query()
            ->whereIn('institutions.status', InstitutionStatus::publiclyVisibleValues())
            ->where(function (Builder $visibilityQuery) use ($accountId): void {
                $visibilityQuery->where('allow_public_event_submission', true);

                if ($accountId !== null && $accountId !== '') {
                    $visibilityQuery->orWhereHas('members', fn (Builder $memberQuery): Builder => $memberQuery->whereKey($accountId));
                }
            })
            ->orderBy('name');

        $this->applySearch($query, (string) $search);

        return $query
            ->limit($limit)
            ->with('names')
            ->get(['institutions.id', 'institutions.name'])
            ->map(fn (Institution $institution): array => [
                'id' => (string) $institution->id,
                'label' => $institution->display_name,
            ])
            ->all();
    }

    /** @param Builder<Institution> $query */
    private function applySearch(Builder $query, string $search): void
    {
        $normalized = trim($search);

        if ($normalized !== '') {
            $this->institutionSearchService->applySearch($query, $normalized);
        }
    }
}
