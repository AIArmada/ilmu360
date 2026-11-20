<?php

namespace App\Features\Engagement\EntryPoints\Http\Data;

use AIArmada\Engagement\Models\Response;
use Illuminate\Support\Arr;
use Spatie\LaravelData\Data;

class EventEngagementListItemData extends Data
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $institution
     * @param  array<string, mixed>|null  $venue
     * @param  list<array<string, mixed>>  $persons
     * @param  array<string, mixed>|null  $pivot
     */
    public function __construct(
        public array $attributes,
        public ?array $institution,
        public ?array $venue,
        public array $persons,
        public ?array $pivot,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload, ?Response $response = null): self
    {
        return new self(
            attributes: Arr::only($payload, [
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
                'event_categories',
                'event_format',
                'language',
            ]),
            institution: is_array($payload['institution'] ?? null) ? $payload['institution'] : null,
            venue: is_array($payload['venue'] ?? null) ? $payload['venue'] : null,
            persons: is_array($payload['persons'] ?? null) ? array_values($payload['persons']) : [],
            pivot: $response instanceof Response ? [
                'id' => (string) $response->getKey(),
                'event_id' => (string) $response->respondable_id,
                'user_id' => (string) $response->responder_id,
                'response_type' => $response->response_type,
                'responded_at' => $response->responded_at?->toJSON(),
            ] : null,
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
            'persons' => $this->persons,
            'pivot' => $this->pivot,
        ]);
    }
}
