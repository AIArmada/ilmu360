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
 */
interface EventAuthoring
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function saveAdminEvent(array $data, ?string $eventId = null): AdminEventSaveResult;

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
     * @return list<string>
     */
    public function personSlugSegmentsForState(array $personIds, ?string $organizerId): array;

    /**
     * @param  array<string, mixed>  $state
     */
    public function syncEventClassifications(string $scopeAlias, string $scopeId, array $state): int;

    /**
     * Replace the event-level reference selection.
     *
     * @param  list<string>  $referenceIds
     */
    public function syncEventReferences(string $eventId, array $referenceIds): void;

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
