<?php

declare(strict_types=1);

namespace App\Features\Persons\Services;

use App\Features\Persons\Api\PersonCatalog;
use App\Features\Persons\Models\Person;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentPersonCatalog implements PersonCatalog
{
    public function __construct(private PersonSearchService $personSearchService) {}

    public function membershipApplicationSubjects(string $search): array
    {
        /** @var Builder<Person> $query */
        $query = Person::query()
            ->where('status', 'verified')
            ->whereIn('status', ['verified', 'pending']);

        $normalized = trim($search);

        if ($normalized !== '') {
            $this->personSearchService->applyIndexedSearch($query, $normalized);
        }

        return $query
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->map(fn (Person $person): array => [
                'id' => (string) $person->id,
                'slug' => (string) $person->slug,
                'label' => $person->formatted_name,
            ])
            ->all();
    }

    public function submissionOptions(?string $accountId, ?string $search = null, int $limit = 50): array
    {
        /** @var Builder<Person> $query */
        $query = Person::query()
            ->whereIn('persons.status', ['verified', 'pending'])
            ->where(function (Builder $visibilityQuery) use ($accountId): void {
                $visibilityQuery->where('allow_public_event_submission', true);

                if ($accountId !== null && $accountId !== '') {
                    $visibilityQuery->orWhereHas('members', fn (Builder $memberQuery): Builder => $memberQuery->whereKey($accountId));
                }
            })
            ->orderBy('name');

        $normalized = trim((string) $search);

        if ($normalized !== '') {
            $this->personSearchService->applyIndexedSearch($query, $normalized);
        }

        return $query
            ->limit($limit)
            ->get()
            ->map(fn (Person $person): array => [
                'id' => (string) $person->id,
                'label' => $person->formatted_name,
            ])
            ->all();
    }
}
