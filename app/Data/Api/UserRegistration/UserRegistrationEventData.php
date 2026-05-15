<?php

namespace App\Data\Api\UserRegistration;

use App\Models\Event;
use App\Models\Institution;
use App\Models\Venue;
use BackedEnum;
use Spatie\LaravelData\Data;

class UserRegistrationEventData extends Data
{
    /**
     * @param  array<string, mixed>|null  $institution
     * @param  array<string, mixed>|null  $venue
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $slug,
        public ?string $starts_at,
        public string $status,
        public string $visibility,
        public ?string $institution_id,
        public ?string $venue_id,
        public ?array $institution,
        public ?array $venue,
    ) {}

    public static function fromModel(Event $event): self
    {
        return new self(
            id: (string) $event->id,
            title: (string) $event->title,
            slug: (string) $event->slug,
            starts_at: $event->starts_at?->toIso8601String(),
            status: (string) $event->status,
            visibility: self::enumValue($event->visibility),
            institution_id: is_string($event->institution_id) ? $event->institution_id : null,
            venue_id: is_string($event->venue_id) ? $event->venue_id : null,
            institution: $event->institution instanceof Institution
                ? UserRegistrationInstitutionData::fromModel($event->institution)->toArray()
                : null,
            venue: $event->venue instanceof Venue
                ? UserRegistrationVenueData::fromModel($event->venue)->toArray()
                : null,
        );
    }

    private static function enumValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
