<?php

declare(strict_types=1);

namespace App\Features\Persons\Queries;

use App\Features\Engagement\Api\EngagementSubjectType;
use App\Features\Engagement\Api\SubjectFollows;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Models\Event;
use App\Features\Persons\Api\Data\PersonListData;
use App\Features\Persons\Api\PersonCards;
use App\Features\Persons\Models\Person;
use App\Features\Persons\Services\PersonSearchService;
use App\Platform\Api\Frontend\Data\CountryData;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final readonly class PersonCardsQuery implements PersonCards
{
    public function __construct(
        private PersonSearchService $search,
        private SubjectFollows $follows,
    ) {}

    public function forPerson(object $person, ?string $accountId = null): array
    {
        if (! $person instanceof Person) {
            throw new InvalidArgumentException('Person cards require a person model.');
        }

        $attributes = $person->getAttributes();
        $isFollowing = array_key_exists('is_following', $attributes)
            ? (bool) $attributes['is_following']
            : ($accountId !== null
                && $this->follows->followStateFor($accountId, EngagementSubjectType::Person, (string) $person->id)->isFollowing);

        return $this->toArray($person, $isFollowing);
    }

    public function forSearch(
        string $query,
        ?string $accountId = null,
        int $limit = 4,
    ): array {
        $personIds = $this->search->resolvedPublicSearchIds($query);

        if ($personIds === []) {
            return ['items' => [], 'total' => 0];
        }

        $followingIds = $accountId !== null
            ? $this->follows->followedSubjectIds($accountId, EngagementSubjectType::Person)
            : [];

        $items = Person::query()
            ->active()
            ->withCount(['events' => function (Builder $query): void {
                $query
                    ->whereNotNull('events.published_at')
                    ->whereIn('events.status', Event::PUBLIC_STATUSES)
                    ->where('events.visibility', EventVisibility::Public)
                    ->whereHas('occurrences')
                    ->where('events.starts_at', '>=', now());
            }])
            ->with(['media', 'addresses'])
            ->whereIn('persons.id', $personIds)
            ->orderBy('name')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (Person $person): array => $this->toArray(
                $person,
                in_array((string) $person->id, $followingIds, true),
            ))
            ->all();

        return ['items' => $items, 'total' => count($personIds)];
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Person $person, bool $isFollowing): array
    {
        return new PersonListData(
            id: (string) $person->id,
            slug: (string) $person->slug,
            name: (string) $person->name,
            middle_name: $person->middle_name,
            family_name: $person->family_name,
            gender: $person->gender instanceof \BackedEnum ? $person->gender->value : null,
            formatted_name: (string) $person->formatted_name,
            status: (string) $person->status,
            verified_by: $person->getAttribute('verified_by'),
            events_count: (int) ($person->events_count ?? 0),
            avatar_url: (string) $person->public_avatar_url,
            country: CountryData::fromAddress($person->primaryAddress())?->toArray(),
            is_following: $isFollowing,
        )->toArray();
    }
}
