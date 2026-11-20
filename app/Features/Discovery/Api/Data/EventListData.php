<?php

declare(strict_types=1);

namespace App\Features\Discovery\Api\Data;

final readonly class EventListData
{
    /**
     * @param  list<array<string, mixed>>  $event_categories
     * @param  array<string, mixed>|null  $institution
     * @param  array<string, mixed>|null  $venue
     * @param  list<array<string, mixed>>  $persons
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $title,
        public ?string $starts_at,
        public ?string $starts_at_local,
        public ?string $starts_on_local_date,
        public ?string $ends_at,
        public ?string $ends_at_local,
        public ?string $timing_display,
        public ?string $prayer_display_text,
        public ?string $end_time_display,
        public string $visibility,
        public string $status,
        public string $status_label,
        public array $event_categories,
        public string $event_format,
        public string $event_format_label,
        public ?string $reference_study_subtitle,
        public ?string $location,
        public bool $is_remote,
        public bool $is_pending,
        public bool $is_cancelled,
        public bool $has_poster,
        public ?string $poster_url,
        public string $card_image_url,
        public ?array $institution,
        public ?array $venue,
        public array $persons,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * Rehydrate a card payload produced by {@see self::toArray()}.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $string = static fn (string $key): string => (string) ($payload[$key] ?? '');
        $nullableString = static fn (string $key): ?string => is_string($payload[$key] ?? null)
            ? $payload[$key]
            : null;

        return new self(
            id: $string('id'),
            slug: $string('slug'),
            title: $string('title'),
            starts_at: $nullableString('starts_at'),
            starts_at_local: $nullableString('starts_at_local'),
            starts_on_local_date: $nullableString('starts_on_local_date'),
            ends_at: $nullableString('ends_at'),
            ends_at_local: $nullableString('ends_at_local'),
            timing_display: $nullableString('timing_display'),
            prayer_display_text: $nullableString('prayer_display_text'),
            end_time_display: $nullableString('end_time_display'),
            visibility: $string('visibility'),
            status: $string('status'),
            status_label: $string('status_label'),
            event_categories: self::arrayOfArrays($payload['event_categories'] ?? []),
            event_format: $string('event_format'),
            event_format_label: $string('event_format_label'),
            reference_study_subtitle: $nullableString('reference_study_subtitle'),
            location: $nullableString('location'),
            is_remote: (bool) ($payload['is_remote'] ?? false),
            is_pending: (bool) ($payload['is_pending'] ?? false),
            is_cancelled: (bool) ($payload['is_cancelled'] ?? false),
            has_poster: (bool) ($payload['has_poster'] ?? false),
            poster_url: $nullableString('poster_url'),
            card_image_url: $string('card_image_url'),
            institution: self::nullableArray($payload['institution'] ?? null),
            venue: self::nullableArray($payload['venue'] ?? null),
            persons: self::arrayOfArrays($payload['persons'] ?? []),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function arrayOfArrays(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_array(...)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function nullableArray(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }
}
