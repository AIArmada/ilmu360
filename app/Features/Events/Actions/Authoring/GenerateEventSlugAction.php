<?php

namespace App\Features\Events\Actions\Authoring;

use AIArmada\CommerceSupport\Support\CanonicalSlug;
use AIArmada\CommerceSupport\Support\StableModelOrder;
use AIArmada\CommerceSupport\Support\UniqueSlug;
use App\Features\Events\Support\AdminEventTimeMapper;
use App\Features\Events\Support\EventModel;
use App\Platform\Models\MorphModelResolver;
use App\Platform\Routing\SyncSlugRedirectAction;
use App\Shared\Events\Enums\EventKeyPersonRole;
use App\Shared\Events\OrganizerResolver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class GenerateEventSlugAction
{
    use AsAction;

    public function __construct(
        private readonly SyncSlugRedirectAction $syncSlugRedirectAction,
    ) {}

    public function syncEventSlugsForTitle(string $title): bool
    {
        $normalizedTitle = trim($title);

        if ($normalizedTitle === '') {
            return false;
        }

        $events = EventModel::query('event')
            ->where('events.title', $normalizedTitle)
            ->with(['persons:id,slug'])
            ->get();

        return StableModelOrder::sync($events, fn (Model $event): bool => $this->syncEventSlug($event));
    }

    public function syncEventSlugsForPersonName(string $personName): bool
    {
        $normalizedPersonName = trim($personName);

        if ($normalizedPersonName === '') {
            return false;
        }

        $personClass = MorphModelResolver::resolve('person');
        $personIds = $personClass::query()
            ->where('name', $normalizedPersonName)
            ->pluck('id');

        $titles = EventModel::query('event')
            ->where(function ($query) use ($normalizedPersonName, $personIds, $personClass): void {
                $query->whereHas('persons', function ($personQuery) use ($normalizedPersonName): void {
                    $personQuery->where('persons.name', $normalizedPersonName);
                })->orWhereHas('involvements', function ($involvementQuery) use ($personIds, $personClass): void {
                    $involvementQuery
                        ->where('involveable_type', (new $personClass)->getMorphClass())
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

        $personClass = MorphModelResolver::resolve('person');

        $titles = EventModel::query('event')
            ->where(function ($query) use ($normalizedPersonId, $personClass): void {
                $query->whereHas('keyPeople', function ($keyPeopleQuery) use ($normalizedPersonId): void {
                    $keyPeopleQuery
                        ->where('involveable_type', 'person')
                        ->where('involveable_id', $normalizedPersonId)
                        ->where('role_code', EventKeyPersonRole::Speaker->value);
                })->orWhereHas('involvements', function ($involvementQuery) use ($normalizedPersonId, $personClass): void {
                    $involvementQuery
                        ->where('involveable_type', (new $personClass)->getMorphClass())
                        ->where('involveable_id', $normalizedPersonId)
                        ->where('role_code', 'organizer')
                        ->where('is_primary', true);
                });
            })
            ->pluck('title');

        return $this->syncEventSlugsForTitles($titles);
    }

    public function syncEventSlug(Model $event): bool
    {
        $slug = $this->forEvent($event);

        return CanonicalSlug::persist($event, $slug, $this->syncSlugRedirectAction);
    }

    /**
     * @param  string[]  $personIds
     * @return list<string>
     */
    public function personSlugSegmentsForState(array $personIds, ?Model $primaryOrganizer): array
    {
        $segments = $this->personSlugSegmentsForPersonIds($personIds);

        if ($segments === [] && $primaryOrganizer instanceof Model && $primaryOrganizer->getMorphClass() === 'person') {
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

        return UniqueSlug::build(
            EventModel::event(),
            $titleSlug,
            $normalizedPersonSlugs,
            $dateSuffix,
            $ignoreEventId,
        );
    }

    public function forEvent(Model $event): string
    {
        return $this->handle(
            (string) $event->getAttribute('title'),
            $this->slugDateForEvent($event),
            is_string($event->getAttribute('timezone')) ? $event->getAttribute('timezone') : null,
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

        $personClass = MorphModelResolver::resolve('person');

        /** @var Collection<string, Model> $personsById */
        $personsById = $personClass::query()
            ->whereIn('id', $normalizedPersonIds)
            ->get(['id', 'slug'])
            ->keyBy(fn (Model $person): string => (string) $person->getKey());

        return collect($normalizedPersonIds)
            ->map(function (string $personId) use ($personsById): ?string {
                $personSlug = $personsById->get($personId)?->getAttribute('slug');

                return is_string($personSlug) && $personSlug !== ''
                    ? $personSlug
                    : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function slugDateForEvent(Model $event): ?CarbonInterface
    {
        $startsAt = $event->getAttribute('starts_at');

        return $startsAt instanceof CarbonInterface ? $startsAt : null;
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
    private function personSlugSegmentsForEvent(Model $event): array
    {
        // Organizer rows pin FQCN involveable types (see
        // EventOrganizerInvolvementSyncTest), which enforced morph maps
        // cannot resolve via MorphTo — resolve the organizer by id instead.
        $event->loadMissing(['persons:id,slug', 'primaryOrganizerInvolvement']);

        $personSlugSegments = $event->getRelation('persons')
            ->map(function (Model $person): ?string {
                $personSlug = $person->getAttribute('slug');

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

        $organizerId = $event->getRelation('primaryOrganizerInvolvement')?->getAttribute('involveable_id');
        $organizer = $organizerId !== null ? OrganizerResolver::find((string) $organizerId) : null;

        if ($organizer instanceof Model && $organizer->getMorphClass() === 'person') {
            $organizerSlug = $organizer->getAttribute('slug');
            if (is_string($organizerSlug) && $organizerSlug !== '') {
                return [$organizerSlug];
            }
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

        // Admin inputs may be localized (d/m/Y); normalize through the
        // shared parser so slug generation accepts what the form accepts.
        $normalized = AdminEventTimeMapper::normalizeEventDateString($date, $timezone ?: (string) config('app.timezone', 'UTC'));

        if ($normalized !== null) {
            return Carbon::parse($normalized, $timezone ?: (string) config('app.timezone', 'UTC'));
        }

        return Carbon::parse($date, $timezone ?: (string) config('app.timezone', 'UTC'));
    }
}
