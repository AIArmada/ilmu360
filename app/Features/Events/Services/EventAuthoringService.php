<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Events\Models\Event;
use AIArmada\Ticketing\Enums\PricingMode;
use App\Features\Events\Actions\Authoring\ConfigureEventAdmissionAction;
use App\Features\Events\Actions\Authoring\GenerateEventSlugAction;
use App\Features\Events\Actions\Authoring\PersistEventAuthoringAction;
use App\Features\Events\Actions\Authoring\SyncEventClassificationsAction;
use App\Features\Events\Actions\Authoring\SyncEventKeyPeopleAction;
use App\Features\Events\Actions\Authoring\SyncEventLanguagesAction;
use App\Features\Events\Actions\Authoring\SyncEventOrganizerAction;
use App\Features\Events\Actions\Scheduling\SyncEventScheduleAction;
use App\Features\Events\Actions\Submission\SubmissionRelationSync;
use App\Features\Events\Api\AdminEventSaveResult;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\EventScheduleInput;
use App\Features\Events\Support\AdminEventFormStateBuilder;
use App\Features\Events\Support\AdminEventTimeMapper;
use App\Features\Events\Support\EventModel;
use App\Shared\Events\Enums\TimingMode;
use App\Shared\Events\OrganizerResolver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Default implementation of the Events authoring boundary.
 *
 * Root transports observe/controllers call this interface; the concrete
 * actions own all persistence.
 */
final readonly class EventAuthoringService implements EventAuthoring
{
    public function __construct(
        private PersistEventAuthoringAction $persistEvent,
        private GenerateEventSlugAction $generateEventSlug,
        private SyncEventClassificationsAction $syncClassifications,
        private SyncEventKeyPeopleAction $syncKeyPeople,
        private SyncEventScheduleAction $syncSchedule,
        private SyncEventLanguagesAction $syncLanguages,
        private SyncEventOrganizerAction $syncOrganizer,
        private AdminEventFormStateBuilder $formState,
        private ConfigureEventAdmissionAction $configureEventAdmission,
        private SubmissionRelationSync $submissionRelations,
    ) {}

    public function saveAdminEvent(array $data, ?string $eventId = null): AdminEventSaveResult
    {
        return $this->persistEvent->handle($data, $eventId);
    }

    public function adminFormDefaults(): array
    {
        return $this->formState->defaultsForCreate();
    }

    public function adminFormState(string $eventId): array
    {
        return $this->formState->formStateForRecord($this->resolveEvent($eventId));
    }

    public function syncEventSlug(string $eventId): bool
    {
        return $this->generateEventSlug->syncEventSlug($this->resolveEvent($eventId));
    }

    public function eventSlug(
        string $title,
        string|\DateTimeInterface|null $date = null,
        ?string $timezone = null,
        ?string $ignoreEventId = null,
        array $personSlugs = [],
    ): string {
        $resolvedDate = $date instanceof \DateTimeInterface && ! $date instanceof CarbonInterface
            ? Carbon::instance($date)
            : $date;

        return $this->generateEventSlug->handle($title, $resolvedDate, $timezone, $ignoreEventId, $personSlugs);
    }

    public function eventSlugForEvent(string $eventId): string
    {
        return $this->generateEventSlug->forEvent($this->resolveEvent($eventId));
    }

    public function syncEventSlugsForTitle(string $title): bool
    {
        return $this->generateEventSlug->syncEventSlugsForTitle($title);
    }

    public function syncEventSlugsForPersonName(string $personName): bool
    {
        return $this->generateEventSlug->syncEventSlugsForPersonName($personName);
    }

    public function syncEventSlugsForPersonId(string $personId): bool
    {
        return $this->generateEventSlug->syncEventSlugsForPersonId($personId);
    }

    public function personSlugSegmentsForPersonIds(array $personIds): array
    {
        return $this->generateEventSlug->personSlugSegmentsForPersonIds($personIds);
    }

    public function personSlugSegmentsForState(array $personIds, ?string $organizerId): array
    {
        return $this->generateEventSlug->personSlugSegmentsForState(
            $personIds,
            $organizerId !== null ? OrganizerResolver::find($organizerId) : null,
        );
    }

    public function syncEventClassifications(string $scopeAlias, string $scopeId, array $state): int
    {
        $scope = match ($scopeAlias) {
            'event' => EventModel::query('event')->withoutOwnerScope()->findOrFail($scopeId),
            'event_occurrence' => EventModel::query('event_occurrence')->withoutOwnerScope()->findOrFail($scopeId),
            'event_session' => EventModel::query('event_session')->withoutOwnerScope()->findOrFail($scopeId),
            default => throw new InvalidArgumentException("Unsupported event classification scope [{$scopeAlias}]."),
        };

        return $this->syncClassifications->handle($scope, $state);
    }

    public function syncEventReferences(string $eventId, array $referenceIds): void
    {
        $event = EventModel::query('event')->withoutOwnerScope()->findOrFail($eventId);

        $this->submissionRelations->syncEventReferences($event, $referenceIds);
    }

    public function syncEventKeyPeople(string $eventId, array $personIds = [], array $otherKeyPeople = []): void
    {
        $this->syncKeyPeople->syncEvent($this->resolveEvent($eventId), $personIds, $otherKeyPeople);
    }

    public function syncSessionKeyPeople(string $sessionId, array $personIds = [], array $otherKeyPeople = []): void
    {
        $session = EventModel::query('event_session')->withoutOwnerScope()->findOrFail($sessionId);

        $this->syncKeyPeople->syncSession($session, $personIds, $otherKeyPeople);
    }

    public function syncOccurrenceKeyPeople(string $occurrenceId, array $personIds = [], array $otherKeyPeople = []): void
    {
        $occurrence = EventModel::query('event_occurrence')->withoutOwnerScope()->findOrFail($occurrenceId);

        $this->syncKeyPeople->syncOccurrence($occurrence, $personIds, $otherKeyPeople);
    }

    public function syncOccurrenceOrganizer(string $occurrenceId, string $organizerId): void
    {
        $occurrence = EventModel::query('event_occurrence')->withoutOwnerScope()->findOrFail($occurrenceId);
        $organizer = OrganizerResolver::find($organizerId);

        if ($organizer === null) {
            return;
        }

        $this->syncKeyPeople->syncOccurrenceOrganizer($occurrence, $organizer);
    }

    public function syncEventSchedule(string $eventId, EventScheduleInput $schedule): void
    {
        $this->syncSchedule->execute(
            $this->resolveEvent($eventId),
            ScheduleKind::tryFrom($schedule->scheduleKind) ?? ScheduleKind::Single,
            startsAt: $this->parseDate($schedule->startsAt),
            endsAt: $this->parseDate($schedule->endsAt),
            timezone: $schedule->timezone,
            timingMode: $schedule->timingMode !== null ? TimingMode::tryFrom($schedule->timingMode) : null,
            prayerReference: $schedule->prayerReference,
            prayerOffset: $schedule->prayerOffset,
            prayerDisplayText: $schedule->prayerDisplayText,
            prayerSource: $schedule->prayerSource,
            prayerFetchedAt: $schedule->prayerFetchedAt,
            prayerZone: $schedule->prayerZone,
            prayerDate: $schedule->prayerDate,
            prayerLat: $schedule->prayerLat,
            prayerLng: $schedule->prayerLng,
            prayerVenueId: $schedule->prayerVenueId,
            prayerInstitutionId: $schedule->prayerInstitutionId,
            prayerCountry: $schedule->prayerCountry,
        );
    }

    public function syncEventLanguages(string $eventId, array|string $languages): array
    {
        return $this->syncLanguages->handle($eventId, $languages);
    }

    public function syncPrimaryOrganizer(string $eventId, ?string $organizerType, ?string $organizerId): void
    {
        $this->syncOrganizer->handle($eventId, $organizerType, $organizerId);
    }

    public function configureAdmission(string $eventId, array $data): void
    {
        $registrationMode = $data['registration_mode'] ?? RegistrationMode::None;
        $registrationMode = $registrationMode instanceof RegistrationMode
            ? $registrationMode
            : RegistrationMode::from((string) $registrationMode);

        $pricingMode = $data['pricing_mode'] ?? PricingMode::Free;
        $pricingMode = $pricingMode instanceof PricingMode
            ? $pricingMode
            : PricingMode::from((string) $pricingMode);

        /** @var Event $event */
        $event = $this->resolveEvent($eventId);

        $this->configureEventAdmission->handle(
            event: $event,
            registrationMode: $registrationMode,
            pricingMode: $pricingMode,
            tickets: (array) ($data['tickets'] ?? []),
            seating: (array) ($data['seating'] ?? []),
        );
    }

    public function normalizePrayerDateString(mixed $value): ?string
    {
        return AdminEventTimeMapper::normalizePrayerDateString($value);
    }

    public function normalizeEventDateString(mixed $date, string $timezone): ?string
    {
        return AdminEventTimeMapper::normalizeEventDateString($date, $timezone);
    }

    public function injectFormTimeFields(array $data): array
    {
        return AdminEventTimeMapper::injectFormTimeFields($data);
    }

    public function normalizeForPersistence(array $data): array
    {
        return AdminEventTimeMapper::normalizeForPersistence($data);
    }

    /** @return \App\Models\Event */
    private function resolveEvent(string $eventId): Model
    {
        return EventModel::query('event')->withoutOwnerScope()->findOrFail($eventId);
    }

    private function parseDate(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
