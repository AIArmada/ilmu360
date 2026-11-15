<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Admin/advanced event authoring boundary.
 *
 * Root adapters (Filament/admin API/MCP/controllers/observers) use this
 * interface while the Event model and transport layers remain conventional.
 * Every method takes scalar identifiers or plain arrays; no Eloquent models
 * cross this boundary.
 *
 * @phpstan-type EventShellAttributes array{
 *     owner_type: string,
 *     owner_id: string,
 *     created_by_type: string,
 *     created_by_id: string,
 *     title: string,
 *     slug: string,
 *     description: string|null,
 *     timezone: string,
 *     institution_id: string|null,
 *     default_venue_id: string|null,
 *     gender: string|null,
 *     age_group: array<int, string>|string|null,
 *     children_allowed: bool|int|string|null,
 *     is_muslim_only: bool|int|string|null,
 *     delivery_mode: string,
 *     event_url: string|null,
 *     live_url: string|null,
 *     visibility: string,
 *     registration_mode: string,
 *     status: string,
 *     metadata: array<string, mixed>
 * }
 * @phpstan-type EventChangeAnnouncementAttributes array{
 *     event_id: string,
 *     replacement_event_id: string|null,
 *     created_by_type: string,
 *     created_by_id: string,
 *     update_type: string,
 *     severity: string,
 *     message: string,
 *     title: string,
 *     notes: string|null,
 *     metadata: array{changed_fields: list<string>, before_snapshot: array<string, mixed>, after_snapshot: array<string, mixed>},
 *     published_at: string,
 *     visibility: string
 * }
 */
interface EventAuthoring
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function saveAdminEvent(array $data, ?string $eventId = null): AdminEventSaveResult;

    /**
     * Create an event shell using the configured Event model and return its ID.
     *
     * @param  EventShellAttributes  $attributes
     */
    public function createEventShell(array $attributes): string;

    /**
     * Create the initial event-level admission policy.
     */
    public function createInitialAccessPolicy(string $eventId, bool $registrationRequired): void;

    /**
     * Apply the event and occurrence mutations included in a published change announcement.
     *
     * @param  array<string, mixed>  $changes
     * @return list<string> Changed fields.
     */
    public function applyChangeAnnouncementMutations(string $eventId, string $type, array $changes): array;

    /**
     * Persist a published change announcement through the package EventUpdate model.
     *
     * @param  EventChangeAnnouncementAttributes  $attributes
     */
    public function createChangeAnnouncement(array $attributes): string;

    /**
     * Apply a normalized contribution payload to an existing event.
     *
     * The payload is the contribution persistence state (UTC schedule
     * strings, explicit-null prayer anchor, cleared end date); it is not
     * re-derived from the admin form contract.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed> Changed attributes.
     */
    public function applyContributionUpdate(string $eventId, array $state): array;

    /**
     * @return array<string, mixed>
     */
    public function adminFormDefaults(): array;

    /**
     * @return array<string, mixed>
     */
    public function adminFormState(string $eventId): array;

    public function syncEventSlug(string $eventId): bool;

    /**
     * @param  list<string>  $personSlugs
     */
    public function eventSlug(
        string $title,
        string|\DateTimeInterface|null $date = null,
        ?string $timezone = null,
        ?string $ignoreEventId = null,
        array $personSlugs = [],
    ): string;

    public function eventSlugForEvent(string $eventId): string;

    public function syncEventSlugsForTitle(string $title): bool;

    public function syncEventSlugsForPersonName(string $personName): bool;

    public function syncEventSlugsForPersonId(string $personId): bool;

    /**
     * @param  list<mixed>  $personIds
     * @return list<string>
     */
    public function personSlugSegmentsForPersonIds(array $personIds): array;

    /**
     * @param  list<mixed>  $personIds
     * @param  'institution'|'person'|null  $organizerKind
     * @return list<string>
     */
    public function personSlugSegmentsForState(array $personIds, ?string $organizerKind, ?string $organizerId): array;

    /**
     * @param  array<string, mixed>  $state
     */
    public function syncEventClassifications(string $scopeAlias, string $scopeId, array $state): int;

    /**
     * Resolve or create an active package taxonomy term for the contribution
     * create-option flow.
     */
    public function ensureTaxonomyTerm(string $eventTaxonomyCode, string $name): string;

    /**
     * Replace the event-level reference selection.
     *
     * @param  list<string>  $referenceIds
     */
    public function syncEventReferences(string $eventId, array $referenceIds): void;

    /**
     * Replace the event-level series selection.
     *
     * @param  list<string>  $seriesIds
     */
    public function syncEventSeries(string $eventId, array $seriesIds): void;

    /**
     * @param  list<string>  $personIds
     * @param  list<array<string, mixed>>  $otherKeyPeople
     */
    public function syncEventKeyPeople(string $eventId, array $personIds = [], array $otherKeyPeople = []): void;

    /**
     * @param  list<string>  $personIds
     * @param  list<array<string, mixed>>  $otherKeyPeople
     */
    public function syncSessionKeyPeople(string $sessionId, array $personIds = [], array $otherKeyPeople = []): void;

    /**
     * @param  list<string>  $personIds
     * @param  list<array<string, mixed>>  $otherKeyPeople
     */
    public function syncOccurrenceKeyPeople(string $occurrenceId, array $personIds = [], array $otherKeyPeople = []): void;

    public function syncOccurrenceOrganizer(string $occurrenceId, string $organizerId): void;

    public function syncEventSchedule(string $eventId, EventScheduleInput $schedule): void;

    /**
     * @param  array<int, string>|string  $languages
     * @return array{before: list<array{id: string, name: string, code: string}>, after: list<array{id: string, name: string, code: string}>}
     */
    public function syncEventLanguages(string $eventId, array|string $languages): array;

    public function syncPrimaryOrganizer(string $eventId, ?string $organizerType, ?string $organizerId): void;

    /**
     * Apply the admission setup for an existing event.
     *
     * Supported `$data` keys: `registration_mode` and `pricing_mode` (package
     * enum instances or their scalar values), `tickets` and optional
     * `seating`. Ticket types, seat maps and access-policy rows are written by
     * the Events admission action; the event is resolved by identifier.
     *
     * @param  array<string, mixed>  $data
     */
    public function configureAdmission(string $eventId, array $data): void;

    public function normalizePrayerDateString(mixed $value): ?string;

    public function normalizeEventDateString(mixed $date, string $timezone): ?string;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function injectFormTimeFields(array $data): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalizeForPersistence(array $data): array;
}
