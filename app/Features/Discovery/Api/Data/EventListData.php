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
}
