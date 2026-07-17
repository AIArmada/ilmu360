<?php

namespace App\Data\Api\Event;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Data\Api\Frontend\Search\ReferenceDetailMediaData;
use App\Enums\EventChangeSeverity;
use App\Enums\EventChangeType;
use App\Models\Event;
use App\Models\EventChangeAnnouncement;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Support\Events\EventCategoryPresenter;
use App\Support\Location\AddressHierarchyFormatter;
use App\Support\Timezone\UserDateTimeFormatter;
use BackedEnum;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class EventPayloadData extends Data
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly array $payload,
    ) {}

    public static function fromModel(Event $event): self
    {
        $event->loadMissing([
            'latestPublishedChangeAnnouncement.replacementEvent',
            'latestPublishedReplacementAnnouncement.replacementEvent',
            'publishedChangeAnnouncements.replacementEvent',
        ]);

        /** @var array<string, mixed> $payload */
        $payload = Arr::except([
            ...OwnerContext::withOwner(null, fn (): array => $event->toArray()),
            'institution_id' => $event->institution_id,
            'venue_id' => $event->default_venue_id,
            'schedule_kind' => $event->schedule_kind,
            'schedule_state' => self::enumValue($event->schedule_state),
            'timing_mode' => self::enumValue($event->timing_mode),
            'prayer_reference' => self::enumValue($event->prayer_reference),
            'prayer_offset' => self::enumValue($event->prayer_offset),
            'prayer_display_text' => $event->prayer_display_text,
            'event_categories' => app(EventCategoryPresenter::class)->forEvent($event),
            'gender' => self::enumValue($event->gender),
            'age_group' => self::enumListValues($event->age_group),
            'children_allowed' => $event->children_allowed,
            'event_format' => self::enumValue($event->delivery_mode),
            'event_url' => $event->event_url,
            'live_url' => $event->live_url,
            'recording_url' => $event->recording_url,
            'views_count' => $event->views_count,
            'saves_count' => $event->saves_count,
            'registrations_count' => $event->registrations_count,
            'going_count' => $event->going_count,
            'is_featured' => $event->is_featured,
            'published_at' => self::utcDateTimeString($event->published_at),
            'is_muslim_only' => $event->is_muslim_only,
            'reference_study_subtitle' => $event->reference_study_subtitle,
            'card_image_url' => $event->card_image_url,
            'poster_url' => self::preferredMediaUrl($event->getFirstMedia('poster'), ['preview', 'card', 'thumb']),
            'has_poster' => $event->hasMedia('poster'),
            'starts_at_local' => self::localDateTimeString($event->starts_at),
            'starts_on_local_date' => self::localDateString($event->starts_at),
            'ends_at_local' => self::localDateTimeString($event->ends_at),
            'timing_display' => $event->timing_display,
            'end_time_display' => $event->ends_at instanceof DateTimeInterface
                ? UserDateTimeFormatter::format($event->ends_at, 'h:i A')
                : null,
        ], [
            'latest_published_change_announcement',
            'latest_published_replacement_announcement',
            'published_change_announcements',
            'incoming_replacement_announcements',
            'latest_incoming_replacement_announcement',
        ]);

        $payload['active_change_notice'] = self::serializeChangeAnnouncement(
            $event->latestPublishedChangeAnnouncement,
            $event,
        );
        $payload['change_announcements'] = $event->publishedChangeAnnouncements
            ->map(fn (EventChangeAnnouncement $announcement): array => self::serializeChangeAnnouncement($announcement, $event))
            ->values()
            ->all();
        $payload['replacement_event'] = self::serializeReplacementEventPreview($event->replacementLinkTarget());

        if ($event->relationLoaded('institution') && $event->institution instanceof Institution) {
            $payload['institution'] = self::serializeInstitutionPayload(
                $event->institution,
                is_array($payload['institution'] ?? null) ? $payload['institution'] : [],
            );
        }

        if ($event->relationLoaded('speakers')) {
            $event->speakers->loadMissing('media');

            $payload['speakers'] = $event->speakers
                ->map(fn (Speaker $speaker): array => EventSpeakerData::fromModel($speaker)->toArray())
                ->values()
                ->all();
        }

        if ($event->relationLoaded('keyPeople')) {
            $payload['key_people'] = $event->keyPeople
                ->map(fn (EventKeyPerson $keyPerson): array => self::serializeKeyPerson($keyPerson))
                ->values()
                ->all();
        }

        if ($event->relationLoaded('references')) {
            $event->references->loadMissing('media');

            $payload['references'] = $event->references
                ->values()
                ->map(function (Reference $reference, int $index) use ($payload): array {
                    $referencePayload = $payload['references'][$index] ?? null;

                    return self::serializeReferencePayload(
                        $reference,
                        is_array($referencePayload) ? $referencePayload : [],
                    );
                })
                ->all();
        }

        return new self(payload: $payload);
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function transform(
        null|TransformationContextFactory|TransformationContext $transformationContext = null,
    ): array {
        return $this->payload;
    }

    private static function enumValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    /**
     * @return list<mixed>|null
     */
    private static function enumListValues(mixed $value): ?array
    {
        if ($value instanceof Collection) {
            return $value
                ->map(static fn (mixed $item): mixed => self::enumValue($item))
                ->values()
                ->all();
        }

        if (is_array($value)) {
            return array_values(array_map(self::enumValue(...), $value));
        }

        if ($value === null) {
            return null;
        }

        return [self::enumValue($value)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function serializeInstitutionPayload(Institution $institution, array $payload): array
    {
        $address = $institution->primaryAddress();
        $addressLine = AddressHierarchyFormatter::format($address);

        return [
            ...Arr::except($payload, ['media']),
            'address_line' => $addressLine !== '' ? $addressLine : null,
            'map_url' => $address?->google_maps_url,
            'map_lat' => $address?->latitude !== null ? (float) $address->latitude : null,
            'map_lng' => $address?->longitude !== null ? (float) $address->longitude : null,
            'waze_url' => $address?->waze_url,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function serializeReferencePayload(Reference $reference, array $payload): array
    {
        /** @var array{front_cover_url: string, back_cover_url: string} $media */
        $media = ReferenceDetailMediaData::fromModel($reference)->toArray();
        $frontCoverUrl = $media['front_cover_url'] !== '' ? $media['front_cover_url'] : null;
        $backCoverUrl = $media['back_cover_url'] !== '' ? $media['back_cover_url'] : null;

        return [
            ...$payload,
            'media' => $media,
            'front_cover_url' => $frontCoverUrl,
            'back_cover_url' => $backCoverUrl,
            'cover_url' => $frontCoverUrl,
            'thumb_url' => $frontCoverUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeKeyPerson(EventKeyPerson $keyPerson): array
    {
        return [
            'id' => (string) $keyPerson->getKey(),
            'role' => self::enumValue($keyPerson->role),
            'name' => $keyPerson->name,
            'visibility' => $keyPerson->visibility,
            'sort_order' => $keyPerson->sort_order,
            'speaker' => $keyPerson->speaker instanceof Speaker
                ? EventSpeakerData::fromModel($keyPerson->speaker)->toArray()
                : null,
        ];
    }

    /**
     * @param  list<string>  $preferredConversions
     */
    private static function preferredMediaUrl(?Media $media, array $preferredConversions = []): ?string
    {
        if (! $media instanceof Media) {
            return null;
        }

        $availableUrl = $preferredConversions === []
            ? $media->getUrl()
            : $media->getAvailableUrl($preferredConversions);

        if ($availableUrl !== '') {
            return $availableUrl;
        }

        $originalUrl = $media->getUrl();

        return $originalUrl !== '' ? $originalUrl : null;
    }

    private static function localDateTimeString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->timezone(UserDateTimeFormatter::resolveTimezone())->format(DATE_ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function localDateString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->timezone(UserDateTimeFormatter::resolveTimezone())->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function serializeChangeAnnouncement(?EventChangeAnnouncement $announcement, Event $rootEvent): ?array
    {
        if (! $announcement instanceof EventChangeAnnouncement) {
            return null;
        }

        $type = $announcement->update_type instanceof EventChangeType
            ? $announcement->update_type
            : EventChangeType::tryFrom((string) $announcement->update_type) ?? EventChangeType::Other;
        $severity = $announcement->severity instanceof EventChangeSeverity
            ? $announcement->severity
            : EventChangeSeverity::tryFrom((string) $announcement->severity) ?? EventChangeSeverity::Info;

        return [
            'id' => (string) $announcement->getKey(),
            'type' => $type->value,
            'type_label' => $type->label(),
            'type_badge_label' => $type->publicBadgeLabel(),
            'severity' => $severity->value,
            'severity_label' => $severity->label(),
            'public_message' => $announcement->message,
            'display_message' => filled($announcement->message)
                ? (string) $announcement->message
                : __('Maklumat majlis ini telah dikemas kini.'),
            'changed_fields' => array_values(data_get($announcement->metadata, 'changed_fields') ?? []),
            'published_at' => $announcement->published_at?->toIso8601String(),
            'replacement_event' => self::serializeReplacementEventPreview(
                $rootEvent->replacementLinkTargetForAnnouncement($announcement),
            ),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function serializeReplacementEventPreview(?Event $event): ?array
    {
        if (! $event instanceof Event) {
            return null;
        }

        $event->loadMissing([
            'media',
            'institution.media',
            'speakers.media',
        ]);

        return [
            'id' => (string) $event->getKey(),
            'route_key' => (string) $event->getRouteKey(),
            'slug' => $event->slug,
            'title' => $event->title,
            'starts_at' => self::utcDateTimeString($event->starts_at),
            'starts_at_local' => self::localDateTimeString($event->starts_at),
            'starts_on_local_date' => self::localDateString($event->starts_at),
            'ends_at' => self::utcDateTimeString($event->ends_at),
            'ends_at_local' => self::localDateTimeString($event->ends_at),
            'timing_display' => $event->timing_display,
            'end_time_display' => $event->ends_at instanceof DateTimeInterface
                ? UserDateTimeFormatter::format($event->ends_at, 'h:i A')
                : null,
            'visibility' => (string) $event->getRawOriginal('visibility'),
            'status' => (string) $event->getRawOriginal('status'),
            'poster_url' => self::preferredMediaUrl($event->getFirstMedia('poster'), ['preview', 'card', 'thumb']),
            'card_image_url' => $event->card_image_url,
        ];
    }

    private static function utcDateTimeString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->utc()->toIso8601String();
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
