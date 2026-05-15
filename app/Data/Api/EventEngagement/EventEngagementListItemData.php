<?php

namespace App\Data\Api\EventEngagement;

use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Arr;
use Spatie\LaravelData\Data;

class EventEngagementListItemData extends Data
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $institution
     * @param  array<string, mixed>|null  $venue
     * @param  list<array<string, mixed>>  $speakers
     * @param  array<string, mixed>|null  $pivot
     */
    public function __construct(
        public array $attributes,
        public ?array $institution,
        public ?array $venue,
        public array $speakers,
        public ?array $pivot,
    ) {}

    public static function fromModel(Event $event): self
    {
        $pivot = $event->relationLoaded('pivot') ? $event->getRelation('pivot') : null;

        return new self(
            attributes: Arr::only($event->attributesToArray(), [
                'id',
                'title',
                'slug',
                'status',
                'visibility',
                'starts_at',
                'ends_at',
                'timezone',
                'published_at',
                'institution_id',
                'venue_id',
                'event_url',
                'live_url',
                'event_type',
                'event_format',
                'language',
                'registrations_count',
                'going_count',
                'saves_count',
            ]),
            institution: $event->relationLoaded('institution') && $event->institution instanceof Institution
                ? Arr::only($event->institution->toArray(), ['id', 'name', 'slug'])
                : null,
            venue: $event->relationLoaded('venue') && $event->venue instanceof Venue
                ? Arr::only($event->venue->toArray(), ['id', 'name'])
                : null,
            speakers: $event->relationLoaded('speakers')
                ? $event->speakers
                    ->map(fn (Speaker $speaker): array => Arr::only($speaker->toArray(), ['id', 'name', 'slug', 'pivot']))
                    ->values()
                    ->all()
                : [],
            pivot: $pivot instanceof Pivot ? $pivot->toArray() : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return array_merge($this->attributes, [
            'institution' => $this->institution,
            'venue' => $this->venue,
            'speakers' => $this->speakers,
            'pivot' => $this->pivot,
        ]);
    }
}
