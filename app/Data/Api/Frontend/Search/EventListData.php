<?php

namespace App\Data\Api\Frontend\Search;

use App\Enums\EventFormat;
use App\Enums\EventType;
use App\Models\Address;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\Venue;
use App\Support\Location\AddressHierarchyFormatter;
use App\Support\Timezone\UserDateTimeFormatter;
use BackedEnum;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class EventListData extends Data
{
    /**
     * @param  list<string>  $event_type
     * @param  array<string, mixed>|null  $institution
     * @param  array<string, mixed>|null  $venue
     * @param  list<array<string, mixed>>  $speakers
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
        public string $event_type_label,
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
        public array $speakers,
    ) {}

    public static function fromModel(Event $event): self
    {
        $eventTypeValues = self::eventTypeValues($event);
        $eventFormat = $event->event_format;
        $eventFormatValue = self::enumValue($eventFormat);
        $status = $event->status;
        $statusValue = (string) $status;

        return new self(
            id: (string) $event->id,
            slug: (string) $event->slug,
            title: (string) $event->title,
            starts_at: self::optionalDateTimeString($event->starts_at),
            starts_at_local: self::optionalLocalDateTimeString($event->starts_at),
            starts_on_local_date: self::optionalLocalDateString($event->starts_at),
            ends_at: self::optionalDateTimeString($event->ends_at),
            ends_at_local: self::optionalLocalDateTimeString($event->ends_at),
            timing_display: $event->timing_display,
            prayer_display_text: $event->prayer_display_text,
            end_time_display: $event->ends_at instanceof DateTimeInterface
                ? UserDateTimeFormatter::format($event->ends_at, 'h:i A')
                : null,
            visibility: self::enumValue($event->visibility),
            status: $statusValue,
            status_label: $status instanceof HasLabel ? $status->getLabel() : Str::headline($statusValue),
            event_type: $eventTypeValues,
            event_type_label: self::eventTypeLabel($eventTypeValues),
            event_format: $eventFormatValue,
            event_format_label: self::eventFormatLabel($eventFormatValue),
            reference_study_subtitle: $event->reference_study_subtitle,
            location: self::eventLocation($event),
            is_remote: in_array($eventFormatValue, [EventFormat::Online->value, EventFormat::Hybrid->value], true),
            is_pending: $statusValue === 'pending',
            is_cancelled: $statusValue === 'cancelled',
            has_poster: $event->hasMedia('poster'),
            poster_url: self::posterUrl($event),
            card_image_url: (string) $event->card_image_url,
            institution: $event->institution instanceof Institution
                ? EventListInstitutionData::fromModel($event->institution)->toArray()
                : null,
            venue: $event->venue instanceof Venue
                ? EventListVenueData::fromModel($event->venue)->toArray()
                : null,
            speakers: $event->speakers
                ->map(fn (Speaker $speaker): array => EventListSpeakerData::fromModel($speaker)->toArray())
                ->values()
                ->all(),
        );
    }

    /**
     * @return list<string>
     */
    private static function eventTypeValues(Event $event): array
    {
        $eventType = $event->event_type;

        if ($eventType instanceof Collection) {
            return $eventType
                ->map(fn (EventType $value): string => $value->value)
                ->filter(fn (string $value): bool => $value !== '')
                ->values()
                ->all();
        }

        if (is_array($eventType)) {
            return array_values(array_filter(array_map(strval(...), $eventType), static fn (string $value): bool => $value !== ''));
        }

        $value = self::enumValue($eventType);

        return $value !== '' ? [$value] : [];
    }

    /**
     * @param  list<string>  $eventTypeValues
     */
    private static function eventTypeLabel(array $eventTypeValues): string
    {
        $value = $eventTypeValues[0] ?? null;

        if (! is_string($value) || $value === '') {
            return __('Umum');
        }

        return EventType::tryFrom($value)?->getLabel() ?? __('Umum');
    }

    private static function eventFormatLabel(string $eventFormatValue): string
    {
        if ($eventFormatValue === '') {
            return EventFormat::Physical->getLabel();
        }

        return EventFormat::tryFrom($eventFormatValue)?->getLabel() ?? Str::headline($eventFormatValue);
    }

    private static function eventLocation(Event $event): ?string
    {
        $venue = $event->venue;
        $institution = $event->institution;
        $primaryLocationName = $venue?->name ?: $institution?->name;
        $address = $venue?->addressModel;

        if (! $address instanceof Address) {
            $address = $institution?->addressModel;
        }

        $parts = array_values(array_filter([
            $primaryLocationName,
            ...AddressHierarchyFormatter::parts($address),
        ], static fn (mixed $value): bool => is_string($value) && $value !== ''));

        if ($parts === []) {
            return null;
        }

        return implode(', ', $parts);
    }

    private static function enumValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private static function posterUrl(Event $event): ?string
    {
        $poster = $event->getFirstMedia('poster');

        if (! $poster instanceof Media) {
            return null;
        }

        return $poster->getAvailableUrl(['preview', 'thumb']) ?: $poster->getUrl();
    }

    private static function optionalDateTimeString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->utc()->format('Y-m-d\TH:i:s.u\Z');
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function optionalLocalDateTimeString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->timezone(UserDateTimeFormatter::resolveTimezone())->format(DATE_ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function optionalLocalDateString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->timezone(UserDateTimeFormatter::resolveTimezone())->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}
