<?php

declare(strict_types=1);

namespace App\Features\Institutions\Queries;

use AIArmada\Addressing\Data\AddressLocationData;
use AIArmada\Addressing\Support\AddressLocationScope;
use App\Features\Institutions\Api\Enums\InstitutionStatus;
use App\Features\Institutions\Api\Orm\InstitutionAuthoringCandidates;
use App\Features\Institutions\Models\Institution;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentInstitutionAuthoringCandidates implements InstitutionAuthoringCandidates
{
    public function options(?User $submitter, ?string $countryId = null): array
    {
        return $this->submitterQuery($submitter, $countryId)
            ->orderBy('name')
            ->with('names')
            ->get(['institutions.id', 'institutions.name'])
            ->mapWithKeys(static fn (Institution $institution): array => [
                (string) $institution->id => (string) $institution->display_name,
            ])
            ->all();
    }

    public function labels(?User $submitter, array $institutionIds): array
    {
        if ($institutionIds === []) {
            return [];
        }

        return $this->submitterQuery($submitter)
            ->whereKey($institutionIds)
            ->orderBy('name')
            ->with('names')
            ->get(['institutions.id', 'institutions.name'])
            ->mapWithKeys(static fn (Institution $institution): array => [
                (string) $institution->id => (string) $institution->display_name,
            ])
            ->all();
    }

    public function canUse(?User $submitter, string $institutionId, ?string $countryId = null): bool
    {
        return $this->submitterQuery($submitter, $countryId)
            ->whereKey($institutionId)
            ->exists();
    }

    public function memberOptions(User $submitter): array
    {
        return $this->memberQuery($submitter)
            ->orderBy('name')
            ->with('names')
            ->get(['institutions.id', 'institutions.name'])
            ->mapWithKeys(static fn (Institution $institution): array => [
                (string) $institution->id => (string) $institution->display_name,
            ])
            ->all();
    }

    public function findMemberCandidate(User $submitter, string $institutionId): ?Institution
    {
        return $this->memberQuery($submitter)
            ->whereKey($institutionId)
            ->first();
    }

    public function belongsToCountry(string $institutionId, string $countryId): bool
    {
        $query = Institution::query()->whereKey($institutionId);

        return app(AddressLocationScope::class)
            ->apply($query, new AddressLocationData(countryId: $countryId))
            ->exists();
    }

    /**
     * @return Builder<Institution>
     */
    private function submitterQuery(?User $submitter, ?string $countryId = null): Builder
    {
        $query = Institution::query()
            ->whereIn('institutions.status', InstitutionStatus::publiclyVisibleValues())
            ->where(function (Builder $visibilityQuery) use ($submitter): void {
                $visibilityQuery->where('allow_public_event_submission', true);

                if ($submitter instanceof User) {
                    $visibilityQuery->orWhereHas(
                        'members',
                        fn (Builder $memberQuery): Builder => $memberQuery->whereKey($submitter->getKey()),
                    );
                }
            });

        if (filled($countryId)) {
            app(AddressLocationScope::class)->apply($query, new AddressLocationData(countryId: $countryId));
        }

        return $query;
    }

    /**
     * @return Builder<Institution>
     */
    private function memberQuery(User $submitter): Builder
    {
        return Institution::query()
            ->whereIn('institutions.status', InstitutionStatus::publiclyVisibleValues())
            ->whereHas('members', fn (Builder $memberQuery): Builder => $memberQuery->whereKey($submitter->getKey()));
    }
}
