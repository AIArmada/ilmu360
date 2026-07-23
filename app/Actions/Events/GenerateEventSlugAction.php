<?php

namespace App\Actions\Events;

use App\Actions\Slugs\Concerns\BuildsUniqueSlug;
use App\Actions\Slugs\Concerns\InteractsWithOrderedSlugModels;
use App\Actions\Slugs\SyncCanonicalSlugAction;
use App\Enums\EventKeyPersonRole;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
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
            ->with(['speakers:id,slug'])
            ->get();

        return $this->syncOrderedModels($events, fn (Event $event): bool => $this->syncEventSlug($event));
    }

    public function syncEventSlugsForSpeakerName(string $speakerName): bool
    {
        $normalizedSpeakerName = trim($speakerName);

        if ($normalizedSpeakerName === '') {
            return false;
        }

        $speakerIds = Speaker::query()
            ->where('name', $normalizedSpeakerName)
            ->pluck('id');

        $titles = Event::query()
            ->where(function ($query) use ($normalizedSpeakerName, $speakerIds): void {
                $query->whereHas('speakers', function ($speakerQuery) use ($normalizedSpeakerName): void {
                    $speakerQuery->where('speakers.name', $normalizedSpeakerName);
                })->orWhereHas('involvements', function ($involvementQuery) use ($speakerIds): void {
                    $involvementQuery
                        ->where('involveable_type', Speaker::class)
                        ->whereIn('involveable_id', $speakerIds)
                        ->where('role_code', 'organizer')
                        ->where('is_primary', true);
                });
            })
            ->pluck('title');

        return $this->syncEventSlugsForTitles($titles);
    }

    public function syncEventSlugsForSpeakerId(string $speakerId): bool
    {
        $normalizedSpeakerId = trim($speakerId);

        if ($normalizedSpeakerId === '') {
            return false;
        }

        $titles = Event::query()
            ->where(function ($query) use ($normalizedSpeakerId): void {
                $query->whereHas('keyPeople', function ($keyPeopleQuery) use ($normalizedSpeakerId): void {
                    $keyPeopleQuery
                        ->where('involveable_type', 'speaker')
                        ->where('involveable_id', $normalizedSpeakerId)
                        ->where('role_code', EventKeyPersonRole::Speaker->value);
                })->orWhereHas('involvements', function ($involvementQuery) use ($normalizedSpeakerId): void {
                    $involvementQuery
                        ->where('involveable_type', Speaker::class)
                        ->where('involveable_id', $normalizedSpeakerId)
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
     * @param  string[]  $speakerIds
     * @return string[]
     */
    public function speakerSlugSegmentsForState(array $speakerIds, Institution|Speaker|null $primaryOrganizer): array
    {
        $segments = $this->speakerSlugSegmentsForSpeakerIds($speakerIds);

        if ($segments === [] && $primaryOrganizer instanceof Speaker) {
            $segments = $this->speakerSlugSegmentsForSpeakerIds([
                (string) $primaryOrganizer->getKey(),
            ]);
        }

        return $segments;
    }

    /**
     * @param  list<string>  $speakerSlugs
     */
    public function handle(
        string $title,
        CarbonInterface|string|null $date = null,
        ?string $timezone = null,
        ?string $ignoreEventId = null,
        array $speakerSlugs = [],
    ): string {
        $normalizedTitle = trim($title);
        $titleSlug = Str::slug($normalizedTitle);

        if ($titleSlug === '') {
            $titleSlug = 'event';
        }

        $normalizedSpeakerSlugs = $this->normalizedSpeakerSlugs($speakerSlugs);
        $dateSuffix = $this->dateSuffix($date, $timezone);

        return $this->buildUniqueSlug(
            Event::class,
            $titleSlug,
            $normalizedSpeakerSlugs,
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
            $this->speakerSlugSegmentsForEvent($event),
        );
    }

    /**
     * @param  list<mixed>  $speakerIds
     * @return list<string>
     */
    public function speakerSlugSegmentsForSpeakerIds(array $speakerIds): array
    {
        $normalizedSpeakerIds = $this->normalizedSpeakerIds($speakerIds);

        if ($normalizedSpeakerIds === []) {
            return [];
        }

        /** @var Collection<string, Speaker> $speakersById */
        $speakersById = Speaker::query()
            ->whereIn('id', $normalizedSpeakerIds)
            ->get(['id', 'slug'])
            ->keyBy(fn (Speaker $speaker): string => (string) $speaker->getKey());

        return collect($normalizedSpeakerIds)
            ->map(function (string $speakerId) use ($speakersById): ?string {
                $speakerSlug = $speakersById->get($speakerId)?->slug;

                return is_string($speakerSlug) && $speakerSlug !== ''
                    ? $speakerSlug
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
     * @param  array<int, mixed>  $speakerSlugs
     * @return list<string>
     */
    private function normalizedSpeakerSlugs(array $speakerSlugs): array
    {
        return collect($speakerSlugs)
            ->map(function (mixed $speakerSlug): ?string {
                if (! is_string($speakerSlug)) {
                    return null;
                }

                $normalizedSpeakerSlug = trim($speakerSlug);

                return $normalizedSpeakerSlug !== '' ? $normalizedSpeakerSlug : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<mixed>  $speakerIds
     * @return list<string>
     */
    private function normalizedSpeakerIds(array $speakerIds): array
    {
        return collect($speakerIds)
            ->map(function (mixed $speakerId): ?string {
                if (! is_string($speakerId) && ! is_int($speakerId)) {
                    return null;
                }

                $normalizedSpeakerId = trim((string) $speakerId);

                return $normalizedSpeakerId !== '' ? $normalizedSpeakerId : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function speakerSlugSegmentsForEvent(Event $event): array
    {
        $event->loadMissing(['speakers:id,slug', 'primaryOrganizerInvolvement.involveable']);

        $speakerSlugSegments = $event->speakers
            ->map(function (Speaker $speaker): ?string {
                $speakerSlug = $speaker->slug;

                return is_string($speakerSlug) && $speakerSlug !== ''
                    ? $speakerSlug
                    : null;
            })
            ->filter()
            ->values()
            ->all();

        if ($speakerSlugSegments !== []) {
            return $speakerSlugSegments;
        }

        $organizer = $event->primaryOrganizerInvolvement?->involveable;

        if ($organizer instanceof Speaker && is_string($organizer->slug) && $organizer->slug !== '') {
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
