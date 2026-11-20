<?php

declare(strict_types=1);

namespace App\Features\Persons\Queries;

use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Models\Event;
use App\Features\Persons\Api\PersonLookup;
use App\Features\Persons\Api\PersonSummary;
use App\Features\Persons\Models\Person;
use App\Features\Persons\Services\PersonSearchService;
use App\Features\Reports\Api\ReportSubject;
use App\Features\Reports\Api\ReportSubjectType;
use App\Models\User;
use App\Platform\Admin\AdminResourceUrl;
use App\Platform\Models\SlugOrUuidResolver;
use App\Shared\Directory\FollowableSubject;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class EloquentPersonLookup implements PersonLookup
{
    public function __construct(
        private SlugOrUuidResolver $resolver,
        private PersonSearchService $search,
    ) {}

    public function findPersonBySlug(string $slug): ?object
    {
        return Person::query()->where('slug', $slug)->first();
    }

    public function findPersonByKey(string $personId): ?object
    {
        return Person::query()->find($personId);
    }

    public function personsForMember(string $accountId): object
    {
        return Person::query()
            ->whereHas('members', fn (Builder $query): Builder => $query->whereKey($accountId))
            ->withCount('members')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    public function submissionNameOptions(): array
    {
        return Person::query()
            ->whereIn('status', ['verified', 'pending'])
            ->orderBy('name')
            ->with('titleAssignments.title.category')
            ->get()
            ->mapWithKeys(static fn (Person $person): array => [
                (string) $person->id => (string) $person->formatted_name,
            ])
            ->all();
    }

    public function resolveIdentifier(string $identifier): ?string
    {
        $entity = $this->entity($identifier);

        return $entity instanceof Person ? (string) $entity->getKey() : null;
    }

    public function findFollowableSubject(string $identifier): ?FollowableSubject
    {
        $person = $this->entity($identifier);

        if (! $person instanceof Person) {
            return null;
        }

        return new FollowableSubject(
            id: (string) $person->getKey(),
            slug: (string) $person->slug,
            type: 'person',
            morphType: $person->getMorphClass(),
            isPubliclyVisible: in_array((string) $person->status, ['verified', 'pending'], true),
        );
    }

    public function findDirectoryFollowableSubject(string $identifier): ?FollowableSubject
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        $person = $this->resolver->first(
            Person::query()
                ->speakers()
                ->whereIn('persons.status', ['verified', 'pending']),
            'persons.slug',
            $identifier,
        );

        if (! $person instanceof Person) {
            return null;
        }

        return new FollowableSubject(
            id: (string) $person->getKey(),
            slug: (string) $person->slug,
            type: 'person',
            morphType: $person->getMorphClass(),
            isPubliclyVisible: true,
        );
    }

    /**
     * @return array<string, string>
     */
    public function authorOptions(string $search = '', int $limit = 50): array
    {
        $term = '%'.trim($search).'%';

        return $this->authorLabelsForModels(
            $this->authorBaseQuery()
                ->where(fn (Builder $query) => $query
                    ->whereLike('name', $term)
                    ->orWhereLike('middle_name', $term)
                    ->orWhereLike('family_name', $term))
                ->orderBy('name')
                ->limit(max(1, $limit))
                ->get(),
        );
    }

    /**
     * @param  list<string>  $personIds
     * @return array<string, string>
     */
    public function authorLabels(array $personIds): array
    {
        $personIds = array_values(array_filter($personIds, static fn (string $id): bool => $id !== ''));

        if ($personIds === []) {
            return [];
        }

        return $this->authorLabelsForModels(
            $this->authorBaseQuery()->whereKey($personIds)->get(),
        );
    }

    /**
     * @return Builder<Person>
     */
    private function authorBaseQuery(): Builder
    {
        return Person::query()
            ->whereIn('status', ['verified', 'pending'])
            ->with('titleAssignments.title.category');
    }

    /**
     * @param  iterable<int, Person>  $persons
     * @return array<string, string>
     */
    private function authorLabelsForModels(iterable $persons): array
    {
        $rows = [];

        foreach ($persons as $person) {
            $formatted = trim((string) $person->formatted_name);
            $rows[(string) $person->getKey()] = [
                'label' => $formatted !== '' ? $formatted : trim((string) $person->name),
                'slug' => (string) $person->slug,
            ];
        }

        $counts = array_count_values(array_column($rows, 'label'));
        $labels = [];

        foreach ($rows as $id => $row) {
            $labels[$id] = ($counts[$row['label']] ?? 0) > 1
                ? $row['label'].' · '.$row['slug']
                : $row['label'];
        }

        return $labels;
    }

    public function find(string $identifier): ?PersonSummary
    {
        $entity = $this->entity($identifier);
        if (! $entity instanceof Person) {
            return null;
        }

        $fallback = (string) $entity->getKey();
        $status = $entity->getAttribute('status');
        $status = $status instanceof BackedEnum ? (string) $status->value : (filled($status) ? (string) $status : null);
        $slug = $entity->getAttribute('slug');
        $image = $entity->public_main_url;

        return new PersonSummary(
            id: $fallback,
            title: filled($entity->formatted_name) ? (string) $entity->formatted_name : $fallback,
            slug: filled($slug) ? (string) $slug : null,
            status: $status,
            publicUrl: route('persons.show', $entity),
            adminUrl: AdminResourceUrl::edit('persons', (string) $entity->getRouteKey()),
            imageUrl: is_string($image) && $image !== '' ? $image : null,
        );
    }

    public function findReportSubject(string $id): ?ReportSubject
    {
        if (trim($id) === '') {
            return null;
        }
        $entity = Person::query()->find($id);

        return $entity instanceof Person ? $this->reportSubject($entity) : null;
    }

    public function resolveReportSubject(string $identifier): ?ReportSubject
    {
        $entity = $this->entity($identifier);

        return $entity instanceof Person ? $this->reportSubject($entity) : null;
    }

    private function entity(string $identifier): ?Person
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        return $this->resolver->first(Person::query(), 'persons.slug', $identifier);
    }

    private function reportSubject(Person $entity): ReportSubject
    {
        $id = (string) $entity->getKey();

        return new ReportSubject(
            type: ReportSubjectType::Person,
            id: $id,
            label: __('Speaker'),
            title: filled($entity->getAttribute('formatted_name')) ? (string) $entity->getAttribute('formatted_name') : $id,
            slug: (string) $entity->getAttribute('slug'),
            publicUrl: route('persons.show', $entity),
            adminUrl: AdminResourceUrl::edit('persons', (string) $entity->getRouteKey()),
            imageUrl: $entity->getAttribute('public_main_url'),
        );
    }

    public function publicSearchIds(string $search): array
    {
        return $this->search->publicSearchIds($search);
    }

    public function exists(string $personId): bool
    {
        return Person::query()->whereKey($personId)->exists();
    }

    public function assertExists(string $personId): void
    {
        if (! $this->exists($personId)) {
            throw (new ModelNotFoundException)->setModel(Person::class, [$personId]);
        }
    }

    public function personMorphType(): string
    {
        return (new Person)->getMorphClass();
    }

    /**
     * @return array<string, string>
     */
    public function directoryOptions(string $search = '', int $limit = 50): array
    {
        $query = Person::query()
            ->whereIn('status', ['verified', 'pending']);

        $normalizedSearch = trim($search);

        if ($normalizedSearch !== '') {
            $query->whereLike('name', '%'.$normalizedSearch.'%');
        }

        return $query
            ->orderBy('name')
            ->with('titleAssignments.title.category')
            ->limit(max(1, $limit))
            ->get(['id', 'name', 'middle_name', 'family_name'])
            ->mapWithKeys(static fn (Person $person): array => [
                (string) $person->id => $person->formatted_name,
            ])
            ->all();
    }

    /**
     * @param  list<string>  $personIds
     * @return array<string, string>
     */
    public function directoryLabels(array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }

        return Person::query()
            ->whereIn('status', ['verified', 'pending'])
            ->whereIn('id', $personIds)
            ->with('titleAssignments.title.category')
            ->get(['id', 'name', 'middle_name', 'family_name'])
            ->mapWithKeys(static fn (Person $person): array => [
                (string) $person->id => $person->formatted_name,
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function nameOptions(string $search = '', int $limit = 50): array
    {
        $query = Person::query();
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

    public function newPersonQuery(): object
    {
        return Person::query();
    }

    /**
     * @return array<string, string>
     */
    public function membershipApplicationOptions(string $search = ''): array
    {
        $query = Person::query()
            ->where('status', 'verified')
            ->whereIn('status', ['verified', 'pending']);

        $normalizedSearch = trim($search);

        if ($normalizedSearch !== '') {
            $query->whereLike('name', "%{$normalizedSearch}%");
        }

        return $query
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->mapWithKeys(static fn (Person $person): array => [
                (string) $person->slug => (string) $person->formatted_name,
            ])
            ->all();
    }

    public function membershipApplicationLabel(string $slug): ?string
    {
        $person = Person::query()
            ->where('status', 'verified')
            ->whereIn('status', ['verified', 'pending'])
            ->where('slug', $slug)
            ->first();

        return $person instanceof Person ? (string) $person->formatted_name : null;
    }

    public function followedPersons(array $personIds): object
    {
        return Person::query()
            ->whereKey($personIds)
            ->with(['media'])
            ->orderBy('name')
            ->get();
    }

    public function personsByIds(array $personIds): object
    {
        return Person::query()
            ->whereKey($personIds)
            ->orderBy('name')
            ->get();
    }

    public function findDetailRecord(string $identifier): ?object
    {
        return Person::query()
            ->with([
                'media',
                'contactMethods',
                'socialProfiles',
                'addresses.country',
                'institutions' => fn ($query) => $query->orderByPivot('is_primary', 'desc')->limit(3),
                'institutions.media',
            ])
            ->tap(fn (Builder $query): Builder => $this->resolver->apply($query, 'persons.slug', $identifier))
            ->first();
    }

    public function baseDirectoryQuery(?string $accountId = null): object
    {
        $query = Person::query();

        if ($accountId !== null) {
            $followsTable = $query->getQuery()->getGrammar()->wrapTable($this->followsTable());

            $query->select('persons.*')
                ->selectRaw(
                    "exists(select 1 from {$followsTable} where {$followsTable}.follower_id = ? and {$followsTable}.followable_id = persons.id and {$followsTable}.followable_type = ? and {$followsTable}.follower_type = ? and {$followsTable}.status = ?) as is_following",
                    [$accountId, $this->personMorphType(), (new User)->getMorphClass(), 'active'],
                );
        }

        return $query->active()
            ->withCount(['events' => function (Builder $events): void {
                $events
                    ->whereNotNull('events.published_at')
                    ->whereIn('events.status', Event::PUBLIC_STATUSES)
                    ->where('events.visibility', EventVisibility::Public)
                    ->whereHas('occurrences')
                    ->where('events.starts_at', '>=', now());
            }])
            ->with(['media', 'addresses']);
    }

    private function followsTable(): string
    {
        return (string) config('engagement.database.tables.follows', 'engagement_follows');
    }

    /**
     * @param  list<string>  $personIds
     * @return array<string, string>
     */
    public function namesByIds(array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }

        return Person::query()
            ->whereKey($personIds)
            ->pluck('name', 'id')
            ->mapWithKeys(static fn (mixed $name, mixed $id): array => [(string) $id => (string) $name])
            ->all();
    }
}
