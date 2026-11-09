<?php

declare(strict_types=1);

namespace App\Support\Authz;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Shared\Auth\AccountActor;
use App\Shared\Membership\MembershipDirectory;
use App\Shared\Membership\MembershipSubjectType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only membership subject lists for account administration surfaces.
 *
 * Membership state is read through the MembershipDirectory contract; the
 * returned models are only presentation data for the user admin screens.
 */
final readonly class MemberDirectorySummary
{
    public function __construct(
        private MembershipDirectory $memberships,
    ) {}

    /**
     * @return Collection<int, Institution>
     */
    public function institutions(AccountActor $account): Collection
    {
        return $this->subjects($account, MembershipSubjectType::Institution, Institution::class, 'name');
    }

    /**
     * @return Collection<int, Person>
     */
    public function persons(AccountActor $account): Collection
    {
        return $this->subjects($account, MembershipSubjectType::Person, Person::class, 'name');
    }

    /**
     * @return Collection<int, Event>
     */
    public function events(AccountActor $account): Collection
    {
        return $this->subjects(
            $account,
            MembershipSubjectType::Event,
            Event::class,
            'created_at',
            'desc',
            ['institution:id,name', 'venue:id,name'],
        );
    }

    /**
     * @return Collection<int, Reference>
     */
    public function references(AccountActor $account): Collection
    {
        return $this->subjects(
            $account,
            MembershipSubjectType::Reference,
            Reference::class,
            'title',
            'asc',
            ['parentReference.parentReference'],
        );
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  list<string>  $relations
     * @return Collection<int, TModel>
     */
    private function subjects(
        AccountActor $account,
        MembershipSubjectType $subjectType,
        string $modelClass,
        string $orderColumn,
        string $direction = 'asc',
        array $relations = [],
    ): Collection {
        $subjectIds = $this->memberships->subjectIdsFor($account->actorId(), $subjectType);

        if ($subjectIds === []) {
            return new Collection;
        }

        /** @var Collection<int, TModel> $subjects */
        $subjects = OwnerContext::withOwner(null, fn (): Collection => $modelClass::query()
            ->with($relations)
            ->whereKey($subjectIds)
            ->orderBy($orderColumn, $direction)
            ->get());

        return $subjects;
    }
}
