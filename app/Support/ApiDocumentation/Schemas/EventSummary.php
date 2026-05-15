<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation\Schemas;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @phpstan-type EventSummaryInstitutionArray array{id: string, name: string, slug: string, type: string|null, display_name: ?string, public_image_url: ?string, logo_url: ?string}
 * @phpstan-type EventSummaryVenueArray array{id: string, name: string, slug: string}
 * @phpstan-type EventSummarySpeakerArray array{id: string, name: string, gender: string|null, formatted_name: string, slug: string, avatar_url: ?string}
 * @phpstan-type EventSummaryArray array{id: string, slug: string, title: string, starts_at: ?string, starts_at_local: ?string, starts_on_local_date: ?string, ends_at: ?string, ends_at_local: ?string, timing_display: ?string, prayer_display_text: ?string, end_time_display: ?string, visibility: string, status: string, status_label: string, event_type: list<string>, event_type_label: ?string, event_format: string, event_format_label: ?string, reference_study_subtitle: ?string, location: ?string, is_remote: bool, is_pending: bool, is_cancelled: bool, has_poster: bool, poster_url: ?string, card_image_url: ?string, institution: EventSummaryInstitutionArray|null, venue: EventSummaryVenueArray|null, speakers: list<EventSummarySpeakerArray>}
 *
 * @implements Arrayable<string, mixed>
 */
#[SchemaName('EventSummary')]
final readonly class EventSummary implements Arrayable
{
    /**
     * @param  list<string>  $event_type
     * @param  array{id: string, name: string, slug: string, type: string|null, display_name: ?string, public_image_url: ?string, logo_url: ?string}|null  $institution
     * @param  array{id: string, name: string, slug: string}|null  $venue
     * @param  list<array{id: string, name: string, gender: string|null, formatted_name: string, slug: string, avatar_url: ?string}>  $speakers
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
        public array $event_type,
        public ?string $event_type_label,
        public string $event_format,
        public ?string $event_format_label,
        public ?string $reference_study_subtitle,
        public ?string $location,
        public bool $is_remote,
        public bool $is_pending,
        public bool $is_cancelled,
        public bool $has_poster,
        public ?string $poster_url,
        public ?string $card_image_url,
        public ?array $institution,
        public ?array $venue,
        public array $speakers,
    ) {}

    /** @return EventSummaryArray */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'starts_at' => $this->starts_at,
            'starts_at_local' => $this->starts_at_local,
            'starts_on_local_date' => $this->starts_on_local_date,
            'ends_at' => $this->ends_at,
            'ends_at_local' => $this->ends_at_local,
            'timing_display' => $this->timing_display,
            'prayer_display_text' => $this->prayer_display_text,
            'end_time_display' => $this->end_time_display,
            'visibility' => $this->visibility,
            'status' => $this->status,
            'status_label' => $this->status_label,
            'event_type' => $this->event_type,
            'event_type_label' => $this->event_type_label,
            'event_format' => $this->event_format,
            'event_format_label' => $this->event_format_label,
            'reference_study_subtitle' => $this->reference_study_subtitle,
            'location' => $this->location,
            'is_remote' => $this->is_remote,
            'is_pending' => $this->is_pending,
            'is_cancelled' => $this->is_cancelled,
            'has_poster' => $this->has_poster,
            'poster_url' => $this->poster_url,
            'card_image_url' => $this->card_image_url,
            'institution' => $this->institution,
            'venue' => $this->venue,
            'speakers' => $this->speakers,
        ];
    }
}
