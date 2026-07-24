<?php

namespace App\Actions\Events;

use App\Actions\Slugs\Concerns\BuildsUniqueSlug;
use App\Actions\Slugs\Concerns\InteractsWithOrderedSlugModels;
use App\Actions\Slugs\SyncCanonicalSlugAction;
use App\Enums\EventKeyPersonRole;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class GenerateEventSlugAction
{
    use AsAction;
    use BuildsUniqueSlug;
    use InteractsWithOrderedSlugModels;

    public function __construct(
        private readonly SyncCanonicalSlugAction $syncCanonicalSlugAction,
    ) {}

    public function syncEventSlugsForTitle(string $title): bool
    {
        $normalizedTitle = trim($title);

        if ($normalizedTitle === '') {
            return false;
        }

        $events = Event::query()
            ->where('events.title', $normalizedTitle)
            ->with(['persons:id,slug'])
            ->get();

        return $this->syncOrderedModels($events, fn (Event $event): bool => $this->syncEventSlug($event));
    }

    public function syncEventSlugsForPersonName(string $personName): bool
    {
        $normalizedPersonName = trim($personName);

        if ($normalizedPersonName === '') {
            return false;
        }

        $personIds = Person::query()
            ->where('name', $normalizedPersonName)
            ->pluck('id');

        $titles = Event::query()
            ->where(function ($query) use ($normalizedPersonName, $personIds): void {
                $query->whereHas('persons', function ($personQuery) use ($normalizedPersonName): void {
                    $personQuery->where('persons.name', $normalizedPersonName);
                })->orWhereHas('involvements', function ($involvementQuery) use ($personIds): void {
                    $involvementQuery
                        ->where('involveable_type', Person::class)
                        ->whereIn('involveable_id', $personIds)
                        ->where('role_code', 'organizer')
                        ->where('is_primary', true);
                });
            })
            ->pluck('title');

        return $this->syncEventSlugsForTitles($titles);
    }

    public function syncEventSlugsForPersonId(string $personId): bool
    {
        $normalizedPersonId = trim($personId);

        if ($normalizedPersonId === '') {
            return false;
        }

        $titles = Event::query()
            ->where(function ($query) use ($normalizedPersonId): void {
                $query->whereHas('keyPeople', function ($keyPeopleQuery) use ($normalizedPersonId): void {
                    $keyPeopleQuery
                        ->where('involveable_type', 'person')
                        ->where('involveable_id', $normalizedPersonId)
                        ->where('role_code', EventKeyPersonRole::Speaker->value);
                })->orWhereHas('involvements', function ($involvementQuery) use ($normalizedPersonId): void {
                    $involvementQuery
                        ->where('involveable_type', Person::class)
                        ->where('involveable_id', $normalizedPersonId)
                        ->where('role_code', 'organizer')
                        ->where('is_primary', true);
                });
            })
            ->pluck('title');

        return $this->syncEventSlugsForTitles($titles);
    }

    public function syncEventSlug(Event $event): bool
    {
        $slug = $this->forEvent($event);

        return $this->syncCanonicalSlugAction->persist($event, $slug);
    }

    /**
     * @param  string[]  $personIds
     * @return string[]
     */
    public function personSlugSegmentsForState(array $personIds, Institution|Person|null $primaryOrganizer): array
    {
        $segments = $this->personSlugSegmentsForPersonIds($personIds);

        if ($segments === [] && $primaryOrganizer instanceof Person) {
            $segments = $this->personSlugSegmentsForPersonIds([
                (string) $primaryOrganizer->getKey(),
            ]);
        }

        return $segments;
    }

    /**
     * @param  list<string>  $personSlugs
     */
    public function handle(
        string $title,
        CarbonInterface|string|null $date = null,
        ?string $timezone = null,
        ?string $ignoreEventId = null,
        array $personSlugs = [],
    ): string {
        $normalizedTitle = trim($title);
        $titleSlug = Str::slug($normalizedTitle);

        if ($titleSlug === '') {
            $titleSlug = 'event';
        }

        $normalizedPersonSlugs = $this->normalizedPersonSlugs($personSlugs);
        $dateSuffix = $this->dateSuffix($date, $timezone);

        return $this->buildUniqueSlug(
            Event::class,
            $titleSlug,
            $normalizedPersonSlugs,
            $dateSuffix,
            $ignoreEventId,
        );
    }

    public function forEvent(Event $event): string
    {
        return $this->handle(
            $event->title,
            $this->slugDateForEvent($event),
            is_string($event->timezone) ? $event->timezone : null,
            (string) $event->getKey(),
            $this->personSlugSegmentsForEvent($event),
        );
    }

    /**
     * @param  list<mixed>  $personIds
     * @return list<string>
     */
    public function personSlugSegmentsForPersonIds(array $personIds): array
    {
        $normalizedPersonIds = $this->normalizedPersonIds($personIds);

        if ($normalizedPersonIds === []) {
            return [];
        }

        /** @var Collection<string, Person> $personsById */
        $personsById = Person::query()
            ->whereIn('id', $normalizedPersonIds)
            ->get(['id', 'slug'])
            ->keyBy(fn (Person $person): string => (string) $person->getKey());

        return collect($normalizedPersonIds)
            ->map(function (string $personId) use ($personsById): ?string {
                $personSlug = $personsById->get($personId)?->slug;

                return is_string($personSlug) && $personSlug !== ''
                    ? $personSlug
                    : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function slugDateForEvent(Event $event): ?CarbonInterface
    {
        return $event->starts_at;
    }

    private function dateSuffix(CarbonInterface|string|null $date, ?string $timezone): string
    {
        $resolvedDate = $this->resolveDate($date, $timezone);

        return $resolvedDate?->format('j-n-y') ?? '';
    }

    /**
     * @param  array<int, mixed>  $personSlugs
     * @return list<string>
     */
    private function normalizedPersonSlugs(array $personSlugs): array
    {
        return collect($personSlugs)
            ->map(function (mixed $personSlug): ?string {
                if (! is_string($personSlug)) {
                    return null;
                }

                $normalizedPersonSlug = trim($personSlug);

                return $normalizedPersonSlug !== '' ? $normalizedPersonSlug : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<mixed>  $personIds
     * @return list<string>
     */
    private function normalizedPersonIds(array $personIds): array
    {
        return collect($personIds)
            ->map(function (mixed $personId): ?string {
                if (! is_string($personId) && ! is_int($personId)) {
                    return null;
                }

                $normalizedPersonId = trim((string) $personId);

                return $normalizedPersonId !== '' ? $normalizedPersonId : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function personSlugSegmentsForEvent(Event $event): array
    {
        $event->loadMissing(['persons:id,slug', 'primaryOrganizerInvolvement.involveable']);

        $personSlugSegments = $event->persons
            ->map(function (Person $person): ?string {
                $personSlug = $person->slug;

                return is_string($personSlug) && $personSlug !== ''
                    ? $personSlug
                    : null;
            })
            ->filter()
            ->values()
            ->all();

        if ($personSlugSegments !== []) {
            return $personSlugSegments;
        }

        $organizer = $event->primaryOrganizerInvolvement?->involveable;

        if ($organizer instanceof Person && is_string($organizer->slug) && $organizer->slug !== '') {
            return [$organizer->slug];
        }

        return [];
    }

    /**
     * @param  Collection<int, mixed>  $titles
     */
    private function syncEventSlugsForTitles(Collection $titles): bool
    {
        $didChange = false;

        foreach ($titles->filter(fn (mixed $title): bool => is_string($title) && trim($title) !== '')->unique()->values() as $title) {
            $didChange = $this->syncEventSlugsForTitle($title) || $didChange;
        }

        return $didChange;
    }

    private function resolveDate(CarbonInterface|string|null $date, ?string $timezone): ?Carbon
    {
        if ($date instanceof CarbonInterface) {
            return Carbon::instance($date)->setTimezone(
                $timezone ?: ($date->getTimezone()->getName() ?: (string) config('app.timezone', 'UTC')),
            );
        }

        if (! is_string($date) || trim($date) === '') {
            return null;
        }

        return Carbon::parse($date, $timezone ?: (string) config('app.timezone', 'UTC'));
    }
}
