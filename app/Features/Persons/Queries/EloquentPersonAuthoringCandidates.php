<?php

declare(strict_types=1);

namespace App\Features\Persons\Queries;

use App\Features\Persons\Api\Orm\PersonAuthoringCandidates;
use App\Features\Persons\Models\Person;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentPersonAuthoringCandidates implements PersonAuthoringCandidates
{
    /**
     * @var list<string>
     */
    private const array ALLOWED_STATUSES = ['verified', 'pending'];

    public function options(?User $submitter): array
    {
        return $this->submitterQuery($submitter)
            ->orderBy('name')
            ->with('titleAssignments.title.category')
            ->get(['id', 'name', 'middle_name', 'family_name'])
            ->mapWithKeys(static fn (Person $person): array => [
                (string) $person->id => (string) $person->formatted_name,
            ])
            ->all();
    }

    public function labels(?User $submitter, array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }

        return $this->submitterQuery($submitter)
            ->whereKey($personIds)
            ->orderBy('name')
            ->with('titleAssignments.title.category')
            ->get(['id', 'name', 'middle_name', 'family_name'])
            ->mapWithKeys(static fn (Person $person): array => [
                (string) $person->id => (string) $person->formatted_name,
            ])
            ->all();
    }

    public function canUse(?User $submitter, string $personId): bool
    {
        return $this->submitterQuery($submitter)
            ->whereKey($personId)
            ->exists();
    }

    public function unusableIds(?User $submitter, array $personIds): array
    {
        $ids = array_values(array_unique(array_filter(
            $personIds,
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        )));

        if ($ids === []) {
            return [];
        }

        $usable = $this->submitterQuery($submitter)
            ->whereKey($ids)
            ->pluck('persons.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return array_values(array_diff($ids, $usable));
    }

    /**
     * @return Builder<Person>
     */
    private function submitterQuery(?User $submitter): Builder
    {
        return Person::query()
            ->whereIn('persons.status', self::ALLOWED_STATUSES)
            ->where(function (Builder $visibilityQuery) use ($submitter): void {
                $visibilityQuery->where('allow_public_event_submission', true);

                if ($submitter instanceof User) {
                    $visibilityQuery->orWhereHas(
                        'members',
                        fn (Builder $memberQuery): Builder => $memberQuery->whereKey($submitter->getKey()),
                    );
                }
            });
    }
}
