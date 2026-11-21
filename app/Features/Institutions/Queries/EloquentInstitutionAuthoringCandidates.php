<?php

declare(strict_types=1);

namespace App\Features\Institutions\Queries;

use AIArmada\Addressing\Data\AddressLocationData;
use AIArmada\Addressing\Support\AddressLocationScope;
use App\Features\Institutions\Api\Enums\InstitutionStatus;
use App\Features\Institutions\Api\InstitutionAuthoringCandidates;
use App\Features\Institutions\Models\Institution;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentInstitutionAuthoringCandidates implements InstitutionAuthoringCandidates
{
    public function options(?string $accountId, ?string $countryId = null): array
    {
        return $this->submitterQuery($accountId, $countryId)
            ->orderBy('name')
            ->with('names')
            ->get(['institutions.id', 'institutions.name'])
            ->mapWithKeys(static fn (Institution $institution): array => [
                (string) $institution->id => (string) $institution->display_name,
            ])
            ->all();
    }

    public function labels(?string $accountId, array $institutionIds): array
    {
        if ($institutionIds === []) {
            return [];
        }

        return $this->submitterQuery($accountId)
            ->whereKey($institutionIds)
            ->orderBy('name')
            ->with('names')
            ->get(['institutions.id', 'institutions.name'])
            ->mapWithKeys(static fn (Institution $institution): array => [
                (string) $institution->id => (string) $institution->display_name,
            ])
            ->all();
    }

    public function canUse(?string $accountId, string $institutionId, ?string $countryId = null): bool
    {
        return $this->submitterQuery($accountId, $countryId)
            ->whereKey($institutionId)
            ->exists();
    }

    public function memberOptions(string $accountId): array
    {
        return $this->memberQuery($accountId)
            ->orderBy('name')
            ->with('names')
            ->get(['institutions.id', 'institutions.name'])
            ->mapWithKeys(static fn (Institution $institution): array => [
                (string) $institution->id => (string) $institution->display_name,
            ])
            ->all();
    }

    public function canMemberUse(string $accountId, string $institutionId): bool
    {
        return $this->memberQuery($accountId)
            ->whereKey($institutionId)
            ->exists();
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
    private function submitterQuery(?string $accountId, ?string $countryId = null): Builder
    {
        $query = Institution::query()
            ->whereIn('institutions.status', InstitutionStatus::publiclyVisibleValues())
            ->where(function (Builder $visibilityQuery) use ($accountId): void {
                $visibilityQuery->where('allow_public_event_submission', true);

                if ($accountId !== null) {
                    $visibilityQuery->orWhereHas(
                        'members',
                        fn (Builder $memberQuery): Builder => $memberQuery->whereKey($accountId),
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
    private function memberQuery(string $accountId): Builder
    {
        return Institution::query()
            ->whereIn('institutions.status', InstitutionStatus::publiclyVisibleValues())
            ->whereHas('members', fn (Builder $memberQuery): Builder => $memberQuery->whereKey($accountId));
    }
}
