<?php

namespace App\Data\Api\Event;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventReference;
use AIArmada\Events\Models\EventTimeExpression;
use AIArmada\Events\Models\VenueSpace;
use App\Data\Api\Frontend\Search\ReferenceDetailMediaData;
use App\Enums\EventChangeSeverity;
use App\Enums\EventChangeType;
use App\Enums\PrayerOffset;
use App\Models\Event;
use App\Models\EventChangeAnnouncement;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\Language;
use App\Models\Person;
use App\Models\Reference;
use App\Services\ShareCards\ShareCardService;
use App\Support\Cache\SelectionCatalogCache;
use App\Support\Events\EventCategoryPresenter;
use App\Support\Location\AddressHierarchyFormatter;
use App\Support\Spaces\SpaceLocationPresenter;
use App\Support\Timezone\UserDateTimeFormatter;
use BackedEnum;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
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
            'primaryLocation.venueSpace',
            'displayOccurrence.media',
            'displayOccurrence.classifications.term',
            'displayOccurrence.languages',
            'displayOccurrence.audiences',
            'displayOccurrence.audienceProfiles',
            'displayOccurrence.links' => static fn (Relation $query): Relation => $query->where('visibility', 'public'),
            'displayOccurrence.timeExpressions' => static function (Relation $query): void {
                $query->whereNull('event_session_id');
            },
            'displayOccurrence.references' => static function (Relation $query): void {
                $query->where('visibility', 'public')->orderBy('sort_order');
            },
            'displayOccurrence.references.referenceable' => static function (MorphTo $relation): void {
                $relation->constrain([
                    Reference::class => static fn (Builder $query): Builder => Reference::applyPublicVisibility($query)
                        ->with(['media', 'authors', 'parentReference']),
                ]);
            },
            'displayOccurrence.locations' => static fn (Relation $query): Relation => $query->whereNull('event_session_id'),
            'displayOccurrence.involvements' => static function (Relation $query): void {
                $query->whereNull('event_session_id')
                    ->where('status', 'active')
                    ->where('visibility', 'public');
            },
            'displayOccurrence.involvements.involveable',
            'displayOccurrence.involvements.role',
            'timeExpressions',
            'keyPeople.person',
        ]);

        $groupedSubmission = is_array($event->metadata['public_submission_group'] ?? null)
            && is_string($event->metadata['public_submission_group']['key'] ?? null);
        $displayOccurrence = $event->displayOccurrence;
        $occurrenceScopedContent = $groupedSubmission
            || is_array($displayOccurrence?->metadata['public_submission'] ?? null);

        $occurrenceScopedContent = $occurrenceScopedContent
            || ($displayOccurrence instanceof EventOccurrence
                && is_array($displayOccurrence->metadata['public_submission'] ?? null));

        $occurrenceAudiences = $displayOccurrence instanceof EventOccurrence
            ? $displayOccurrence->audiences
            : collect();
        $occurrencePrayerExpression = $displayOccurrence instanceof EventOccurrence
            ? $displayOccurrence->timeExpressions->firstWhere('anchor_type', 'prayer')
            : null;
        $occurrenceLinks = $displayOccurrence instanceof EventOccurrence ? $displayOccurrence->links : collect();

        /** @var array<string, mixed> $payload */
        $payload = Arr::except([
            ...OwnerContext::withOwner(null, fn (): array => $event->toArray()),
            'institution_id' => $event->institution_id,
            'schedule_kind' => self::enumValue($event->schedule_kind),
            'timing_mode' => $occurrencePrayerExpression instanceof EventTimeExpression || $event->isPrayerRelative()
                ? 'prayer_relative'
                : 'absolute',
            'prayer_reference' => $occurrencePrayerExpression->anchor_code ?? self::enumValue($event->prayer_reference),
            'prayer_offset' => self::signedPrayerOffset($occurrencePrayerExpression, $event->prayer_offset),
            'prayer_display_text' => $occurrencePrayerExpression->display_label ?? $event->prayer_display_text,
            'event_categories' => app(EventCategoryPresenter::class)->forEvent($event),
            'gender' => $occurrenceScopedContent && $displayOccurrence instanceof EventOccurrence
                ? $occurrenceAudiences->firstWhere('audience_type', 'gender')?->value
                : self::enumValue($event->gender),
            'age_group' => $occurrenceScopedContent && $displayOccurrence instanceof EventOccurrence
                ? $occurrenceAudiences->where('audience_type', 'age_group')->pluck('value')->values()->all()
                : self::enumListValues($event->age_group),
            'children_allowed' => $occurrenceScopedContent && $displayOccurrence instanceof EventOccurrence
                ? (bool) $displayOccurrence->audienceProfiles->first()?->is_child_friendly
                : $event->children_allowed,
            'event_url' => $occurrenceLinks->firstWhere('link_type', 'external')->url ?? $event->event_url,
            'live_url' => $occurrenceLinks->firstWhere('link_type', 'streaming')->url ?? $event->live_url,
            'recording_url' => $event->recording_url,
            'is_featured' => $event->is_featured,
            'published_at' => self::utcDateTimeString($event->published_at),
            'is_muslim_only' => $occurrenceScopedContent && $displayOccurrence instanceof EventOccurrence
                ? $occurrenceAudiences->contains(fn ($audience): bool => $audience->audience_type === 'religion' && $audience->value === 'muslim_only')
                : $event->is_muslim_only,
            'description' => $occurrenceScopedContent && $displayOccurrence instanceof EventOccurrence
                ? self::occurrenceDescription($displayOccurrence) ?? $event->description_text
                : $event->description_text,
            'cover_url' => $occurrenceScopedContent && $displayOccurrence instanceof EventOccurrence
                ? self::preferredMediaUrl($displayOccurrence->getFirstMedia('cover'), ['banner', 'thumb'])
                    ?? self::preferredMediaUrl($displayOccurrence->getFirstMedia('poster'), ['preview', 'thumb'])
                    ?? self::eventCoverUrl($event)
                : self::eventCoverUrl($event),
            'reference_study_subtitle' => $event->reference_study_subtitle,
            'card_image_url' => $event->card_image_url,
            'share_card_url' => app(ShareCardService::class)->cardUrl($event),
            'poster_url' => self::preferredMediaUrl(
                $event->getFirstMedia('poster') ?? $displayOccurrence?->getFirstMedia('poster'),
                ['poster_thumb', 'preview', 'thumb'],
            ),
            'has_poster' => $event->hasMedia('poster') || ($displayOccurrence?->hasMedia('poster') ?? false),
            'starts_at_local' => self::localDateTimeString($event->starts_at),
            'starts_on_local_date' => self::localDateString($event->starts_at),
            'ends_at_local' => self::localDateTimeString($event->ends_at),
            'timing_display' => $event->timing_display,
            'end_time_display' => $event->ends_at instanceof DateTimeInterface
                ? UserDateTimeFormatter::format($event->ends_at, 'h:i A')
                : null,
        ], [
            'type',
            'event_format',
            'venue_id',
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

        if (
            $event->default_venue_id === null
            && $event->relationLoaded('institution')
            && $event->institution instanceof Institution
        ) {
            $payload['institution'] = self::serializeInstitutionPayload(
                $event->institution,
                is_array($payload['institution'] ?? null) ? $payload['institution'] : [],
            );

            $location = $event->primaryLocation;
            $space = $location?->venueSpace;

            if ($space instanceof VenueSpace) {
                $payload['institution_space'] = [
                    'id' => (string) $space->getKey(),
                    'name' => SpaceLocationPresenter::name($location),
                    'capacity' => SpaceLocationPresenter::effectiveCapacity($location, (string) $event->institution_id),
                ];
            } else {
                $payload['institution_space'] = null;
            }
        }

        if ($event->relationLoaded('persons')) {
            $event->persons->loadMissing('media');

            $payload['persons'] = $event->persons
                ->map(fn (Person $person): array => EventPersonData::fromModel($person)->toArray())
                ->values()
                ->all();
        }

        if ($event->default_venue_id !== null && $event->primaryLocation?->venueSpace !== null) {
            $space = $event->primaryLocation->venueSpace;
            $payload['venue_space'] = [
                'id' => (string) $space->getKey(),
                'name' => SpaceLocationPresenter::name($event->primaryLocation),
                'capacity' => SpaceLocationPresenter::effectiveCapacity($event->primaryLocation),
            ];
        } else {
            $payload['venue_space'] = null;
        }

        if ($event->relationLoaded('keyPeople')) {
            $payload['key_people'] = $event->keyPeople
                ->map(fn (EventKeyPerson $keyPerson): array => self::serializeKeyPerson($keyPerson))
                ->values()
                ->all();
        }

        if ($event->relationLoaded('references')) {
            $event->references->loadMissing(['media', 'parentReference.parentReference', 'authors.titleAssignments.title.category', 'parentReference.authors.titleAssignments.title.category', 'parentReference.parentReference.authors.titleAssignments.title.category']);

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

        if ($displayOccurrence instanceof EventOccurrence) {
            $languageCodes = $displayOccurrence->languages
                ->pluck('language_code')
                ->filter(fn (mixed $code): bool => is_string($code) && $code !== '')
                ->unique()
                ->values();
            $languages = $languageCodes->isEmpty()
                ? collect()
                : Language::query()->whereIn('code', $languageCodes)->get();
            $occurrenceReferences = $displayOccurrence->references
                ->map(fn (EventReference $row): ?array => self::serializeOccurrenceReference($row))
                ->filter()
                ->values();
            $occurrenceClassifications = $displayOccurrence->classifications
                ->filter(fn ($classification): bool => $classification->term !== null)
                ->map(fn ($classification): array => [
                    'id' => (string) $classification->term->getKey(),
                    'name' => (string) $classification->term->name,
                    'taxonomy_code' => (string) $classification->taxonomy_code,
                ])
                ->values();

            if ($occurrenceScopedContent) {
                $payload['languages'] = $languages->toArray();
                $payload['references'] = $occurrenceReferences->all();
                $payload['classifications'] = $occurrenceClassifications->all();
            }
            $payload['display_occurrence'] = [
                'id' => (string) $displayOccurrence->getKey(),
                'title' => (string) $displayOccurrence->title,
                'slug' => (string) $displayOccurrence->slug,
                'starts_at' => self::utcDateTimeString($displayOccurrence->starts_at),
                'starts_at_local' => self::localDateTimeString($displayOccurrence->starts_at),
                'ends_at' => self::utcDateTimeString($displayOccurrence->ends_at),
                'ends_at_local' => self::localDateTimeString($displayOccurrence->ends_at),
                'timezone' => $displayOccurrence->timezone,
                'status' => (string) $displayOccurrence->status,
                'visibility' => (string) $displayOccurrence->visibility,
                'delivery_mode' => (string) $displayOccurrence->delivery_mode,
                'description' => self::occurrenceDescription($displayOccurrence),
                'gender' => $occurrenceAudiences->firstWhere('audience_type', 'gender')?->value,
                'age_group' => $occurrenceAudiences->where('audience_type', 'age_group')->pluck('value')->values()->all(),
                'children_allowed' => (bool) $displayOccurrence->audienceProfiles->first()?->is_child_friendly,
                'is_muslim_only' => $occurrenceAudiences->contains(fn ($audience): bool => $audience->audience_type === 'religion' && $audience->value === 'muslim_only'),
                'languages' => $languageCodes->all(),
                'classifications' => $occurrenceClassifications->all(),
                'references' => $occurrenceReferences->all(),
                'people' => $displayOccurrence->involvements
                    ->map(fn ($involvement): array => [
                        'id' => (string) $involvement->getKey(),
                        'name' => (string) ($involvement->display_name ?: (
                            $involvement->involveable instanceof Person || $involvement->involveable instanceof Institution
                                ? $involvement->involveable->name
                                : null
                        )),
                        'role' => $involvement->role?->name,
                    ])
                    ->values()
                    ->all(),
                'links' => $occurrenceLinks
                    ->map(fn ($link): array => [
                        'type' => (string) $link->link_type,
                        'url' => (string) $link->url,
                    ])
                    ->values()
                    ->all(),
                'prayer_display_text' => $occurrencePrayerExpression?->display_label,
                'prayer_reference' => $occurrencePrayerExpression?->anchor_code,
                'prayer_offset' => self::signedPrayerOffset($occurrencePrayerExpression, null),
                'prayer_metadata' => data_get($occurrencePrayerExpression?->metadata, 'prayer'),
                'organizer' => self::serializeOccurrenceOrganizer($displayOccurrence),
                'cover_url' => self::preferredMediaUrl($displayOccurrence->getFirstMedia('cover'), ['banner', 'thumb']),
                'poster_url' => self::preferredMediaUrl($displayOccurrence->getFirstMedia('poster'), ['preview', 'thumb']),
                'card_image_url' => $event->card_image_url,
            ];

            if ($occurrenceScopedContent && ! array_key_exists('summary', $payload)) {
                $payload['summary'] = null;
            }
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

    private static function signedPrayerOffset(?EventTimeExpression $expression, mixed $parentOffset): ?int
    {
        if ($expression instanceof EventTimeExpression) {
            if ($expression->offset_minutes === null || ! in_array($expression->relation, ['before', 'after'], true)) {
                return null;
            }

            $minutes = abs((int) $expression->offset_minutes);

            return $expression->relation === 'before' ? -$minutes : $minutes;
        }

        $offset = $parentOffset instanceof PrayerOffset
            ? $parentOffset
            : (is_string($parentOffset) ? PrayerOffset::tryFrom($parentOffset) : null);

        return $offset?->minutes();
    }

    /** @return array{id: string, type: string, name: string, slug: ?string}|null */
    private static function serializeOccurrenceOrganizer(EventOccurrence $occurrence): ?array
    {
        $involvement = $occurrence->involvements->firstWhere('role_code', 'organizer');

        if ($involvement === null) {
            return null;
        }

        $organizer = $involvement->involveable;

        if (! $organizer instanceof Institution && ! $organizer instanceof Person) {
            return null;
        }

        return [
            'id' => (string) $organizer->getKey(),
            'type' => $organizer instanceof Institution ? 'institution' : 'person',
            'name' => (string) $organizer->name,
            'slug' => is_string($organizer->slug ?? null) ? $organizer->slug : null,
        ];
    }

    private static function occurrenceDescription(EventOccurrence $occurrence): ?string
    {
        $description = data_get($occurrence->metadata, 'description_localized');

        if (is_array($description)) {
            foreach ([app()->getLocale(), config('app.fallback_locale')] as $locale) {
                if (is_string($locale) && is_string($description[$locale] ?? null) && trim($description[$locale]) !== '') {
                    return $description[$locale];
                }
            }

            foreach ($description as $value) {
                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private static function serializeOccurrenceReference(EventReference $row): ?array
    {
        if ($row->referenceable instanceof Reference) {
            $reference = $row->referenceable;
            $reference->loadMissing([
                'media',
                'parentReference.parentReference',
                'authors.titleAssignments.title.category',
                'parentReference.authors.titleAssignments.title.category',
                'parentReference.parentReference.authors.titleAssignments.title.category',
            ]);

            return self::serializeReferencePayload($reference, $reference->toArray());
        }

        if (! filled($row->title) && ! filled($row->url) && ! filled($row->citation)) {
            return null;
        }

        return [
            'id' => (string) $row->getKey(),
            'reference_type' => (string) $row->reference_type,
            'title' => $row->title,
            'url' => $row->url,
            'citation' => $row->citation,
            'notes' => $row->notes,
        ];
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
        $authors = $reference->effectiveAuthorsStructured();

        return [
            ...$payload,
            'display_title' => $reference->displayTitle(),
            'authors' => $authors,
            'author_ids' => array_column($authors, 'id'),
            'parent_id' => $reference->parent_id,
            'record_kind' => $reference->recordKindValue(),
            'edition_number' => $reference->edition_number,
            'edition_label' => $reference->edition_label,
            'isbn' => $reference->isbn,
            'language' => $reference->language,
            'language_label' => app(SelectionCatalogCache::class)->languageLabel($reference->language),
            'url' => $reference->url,
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
            'role' => $keyPerson->role_code,
            'name' => $keyPerson->display_name,
            'visibility' => $keyPerson->visibility,
            'sort_order' => $keyPerson->sort_order,
            'person' => $keyPerson->person instanceof Person
                ? EventPersonData::fromModel($keyPerson->person)->toArray()
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

    private static function eventCoverUrl(Event $event): ?string
    {
        $attributes = $event->getAttributes();
        $coverUrl = array_key_exists('cover_url', $attributes)
            ? $event->getAttribute('cover_url')
            : null;

        if (is_string($coverUrl) && $coverUrl !== '') {
            return $coverUrl;
        }

        return self::preferredMediaUrl($event->getFirstMedia('cover'), ['banner', 'thumb']);
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
            'persons.media',
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
            'poster_url' => self::preferredMediaUrl($event->getFirstMedia('poster'), ['poster_thumb']),
            'card_image_url' => $event->card_image_url,
            'share_card_url' => app(ShareCardService::class)->cardUrl($event),
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
