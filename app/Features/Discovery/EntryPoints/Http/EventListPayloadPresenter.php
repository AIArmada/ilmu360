<?php

declare(strict_types=1);

namespace App\Features\Discovery\EntryPoints\Http;

use App\Features\Discovery\Api\Data\EventListData;
use App\Features\Discovery\Api\Orm\EventListPayloads;
use App\Features\Discovery\EntryPoints\Http\Data\EventListInstitutionData;
use App\Features\Discovery\EntryPoints\Http\Data\EventListPersonData;
use App\Features\Discovery\EntryPoints\Http\Data\EventListVenueData;
use App\Features\Events\Api\Enums\EventFormat;
use App\Features\Events\Api\Orm\EventCategoryPayloads;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\Places\Models\Venue;
use App\Platform\Location\AddressHierarchyFormatter;
use App\Platform\Time\UserDateTimeFormatter;
use BackedEnum;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Builds the public event-card payload from a hydrated event model.
 */
final readonly class EventListPayloadPresenter implements EventListPayloads
{
    public function __construct(
        private EventCategoryPayloads $eventCategories,
    ) {}

    public function forEvent(object $event): EventListData
    {
        if (! $event instanceof Event) {
            throw new \InvalidArgumentException(sprintf(
                'EventListPayloadPresenter expects an instance of %s.',
                Event::class,
            ));
        }

        $event->loadMissing('displayOccurrence.media');

        $eventCategories = $this->eventCategories->forEventPayload($event);
        $eventFormat = $event->delivery_mode;
        $eventFormatValue = $this->enumValue($eventFormat);
        $status = $event->status;
        $statusValue = (string) $status;

        return new EventListData(
            id: (string) $event->id,
            slug: (string) $event->slug,
            title: (string) $event->title,
            starts_at: $this->optionalDateTimeString($event->starts_at),
            starts_at_local: $this->optionalLocalDateTimeString($event->starts_at),
            starts_on_local_date: $this->optionalLocalDateString($event->starts_at),
            ends_at: $this->optionalDateTimeString($event->ends_at),
            ends_at_local: $this->optionalLocalDateTimeString($event->ends_at),
            timing_display: $event->timing_display,
            prayer_display_text: $event->prayer_display_text,
            end_time_display: $event->ends_at instanceof DateTimeInterface
                ? UserDateTimeFormatter::format($event->ends_at, 'h:i A')
                : null,
            visibility: $this->enumValue($event->visibility),
            status: $statusValue,
            status_label: $status instanceof HasLabel ? $status->getLabel() : Str::headline($statusValue),
            event_categories: $eventCategories,
            event_format: $eventFormatValue,
            event_format_label: $this->eventFormatLabel($eventFormatValue),
            reference_study_subtitle: $event->reference_study_subtitle,
            location: $this->eventLocation($event),
            is_remote: in_array($eventFormatValue, [EventFormat::Online->value, EventFormat::Hybrid->value], true),
            is_pending: $statusValue === 'pending',
            is_cancelled: $statusValue === 'cancelled',
            has_poster: $event->hasMedia('poster') || ($event->displayOccurrence?->hasMedia('poster') ?? false),
            poster_url: $this->posterUrl($event),
            card_image_url: (string) $event->card_image_url,
            institution: $event->institution instanceof Institution
                ? EventListInstitutionData::fromModel($event->institution)->toArray()
                : null,
            venue: $event->venue instanceof Venue
                ? EventListVenueData::fromModel($event->venue)->toArray()
                : null,
            persons: $event->persons
                ->map(fn (Person $person): array => EventListPersonData::fromModel($person)->toArray())
                ->values()
                ->all(),
        );
    }

    private function eventFormatLabel(string $eventFormatValue): string
    {
        if ($eventFormatValue === '') {
            return EventFormat::Physical->getLabel();
        }

        return EventFormat::tryFrom($eventFormatValue)?->getLabel() ?? Str::headline($eventFormatValue);
    }

    private function eventLocation(Event $event): ?string
    {
        $primaryLocationName = $event->resolvedLocationName();
        $address = $event->resolvedLocationAddress();

        $parts = array_values(array_filter([
            $primaryLocationName,
            ...AddressHierarchyFormatter::parts($address),
        ], static fn (mixed $value): bool => is_string($value) && $value !== ''));

        if ($parts === []) {
            return null;
        }

        return implode(', ', $parts);
    }

    private function enumValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function posterUrl(Event $event): ?string
    {
        $poster = $event->getFirstMedia('poster') ?? $event->displayOccurrence?->getFirstMedia('poster');

        if (! $poster instanceof Media) {
            return null;
        }

        return $poster->getAvailableUrl(['poster_thumb']) ?: $poster->getUrl();
    }

    private function optionalDateTimeString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->utc()->format('Y-m-d\TH:i:s.u\Z');
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function optionalLocalDateTimeString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->timezone(UserDateTimeFormatter::resolveTimezone())->format(DATE_ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function optionalLocalDateString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->timezone(UserDateTimeFormatter::resolveTimezone())->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}
