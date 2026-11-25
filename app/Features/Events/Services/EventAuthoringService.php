<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Data\RegistrationMode;
use AIArmada\Ticketing\Enums\PricingMode;
use App\Features\Events\Actions\Authoring\ApplyChangeAnnouncementMutationsAction;
use App\Features\Events\Actions\Authoring\ApplyEventContributionAction;
use App\Features\Events\Actions\Authoring\ConfigureEventAdmissionAction;
use App\Features\Events\Actions\Authoring\CreateEventChangeAnnouncementAction;
use App\Features\Events\Actions\Authoring\CreateEventShellAction;
use App\Features\Events\Actions\Authoring\CreateInitialEventAccessPolicyAction;
use App\Features\Events\Actions\Authoring\EnsureEventTaxonomyTermAction;
use App\Features\Events\Actions\Authoring\GenerateEventSlugAction;
use App\Features\Events\Actions\Authoring\PersistEventAuthoringAction;
use App\Features\Events\Actions\Authoring\SyncEventClassificationsAction;
use App\Features\Events\Actions\Authoring\SyncEventKeyPeopleAction;
use App\Features\Events\Actions\Authoring\SyncEventLanguagesAction;
use App\Features\Events\Actions\Authoring\SyncEventOrganizerAction;
use App\Features\Events\Actions\Scheduling\SyncEventScheduleFromInputAction;
use App\Features\Events\Actions\Submission\SubmissionRelationSync;
use App\Features\Events\Api\AdminEventSaveResult;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\EventScheduleInput;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventKeyPerson;
use App\Features\Events\Models\Series;
use App\Features\Events\Support\AdminEventFormStateBuilder;
use App\Features\Events\Support\AdminEventTimeMapper;
use App\Features\Events\Support\EventModel;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Default implementation of the Events authoring boundary.
 *
 * Root transports observe/controllers call this interface; the concrete
 * actions own all persistence.
 *
 * @phpstan-import-type EventShellAttributes from EventAuthoring
 * @phpstan-import-type EventChangeAnnouncementAttributes from EventAuthoring
 */
final readonly class EventAuthoringService implements EventAuthoring
{
    public function __construct(
        private PersistEventAuthoringAction $persistEvent,
        private CreateEventShellAction $createEventShellAction,
        private CreateInitialEventAccessPolicyAction $createInitialEventAccessPolicyAction,
        private ApplyChangeAnnouncementMutationsAction $applyChangeAnnouncementMutationsAction,
        private CreateEventChangeAnnouncementAction $createEventChangeAnnouncementAction,
        private GenerateEventSlugAction $generateEventSlug,
        private SyncEventClassificationsAction $syncClassifications,
        private SyncEventKeyPeopleAction $syncKeyPeople,
        private SyncEventScheduleFromInputAction $syncScheduleFromInput,
        private SyncEventLanguagesAction $syncLanguages,
        private SyncEventOrganizerAction $syncOrganizer,
        private ApplyEventContributionAction $applyEventContribution,
        private EnsureEventTaxonomyTermAction $ensureTaxonomyTerm,
        private AdminEventFormStateBuilder $formState,
        private ConfigureEventAdmissionAction $configureEventAdmission,
        private SubmissionRelationSync $submissionRelations,
    ) {}

    public function eventTable(): string
    {
        return (new Event)->getTable();
    }

    public function eventKeyPersonTable(): string
    {
        return (new EventKeyPerson)->getTable();
    }

    public function eventMorphType(): string
    {
        return (new Event)->getMorphClass();
    }

    public function seriesMorphType(): string
    {
        return (new Series)->getMorphClass();
    }

    public function eventExists(string $eventId): bool
    {
        return Event::query()->whereKey($eventId)->exists();
    }

    /**
     * @return list<string>
     */
    public function eventPersonIds(string $eventId): array
    {
        $event = Event::query()->find($eventId);

        if (! $event instanceof Event) {
            return [];
        }

        $persons = $event->persons();

        return $persons
            ->pluck($persons->getRelated()->getQualifiedKeyName())
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     timezone: string,
     *     venue_id: string|null,
     *     institution_id: string|null,
     *     persisted_address_id: string|null
     * }|null
     */
    public function eventPrayerScope(string $eventId): ?array
    {
        return OwnerContext::withOwner(null, function () use ($eventId): ?array {
            $event = Event::query()->find($eventId);

            if (! $event instanceof Event) {
                return null;
            }

            $venueId = $event->getAttribute('default_venue_id');
            $institutionId = $event->getAttribute('institution_id');
            $address = $event->resolvedLocationAddress();

            return [
                'timezone' => is_string($event->timezone) ? $event->timezone : '',
                'venue_id' => is_string($venueId) && $venueId !== '' ? $venueId : null,
                'institution_id' => is_string($institutionId) && $institutionId !== '' ? $institutionId : null,
                'persisted_address_id' => $address instanceof \AIArmada\Addressing\Models\Address ? (string) $address->getKey() : null,
            ];
        });
    }

    public function saveAdminEvent(array $data, ?string $eventId = null): AdminEventSaveResult
    {
        return $this->persistEvent->handle($data, $eventId);
    }

    /**
     * @param  EventShellAttributes  $attributes
     */
    public function createEventShell(array $attributes): string
    {
        return $this->createEventShellAction->handle($attributes);
    }

    public function createInitialAccessPolicy(string $eventId, bool $registrationRequired): void
    {
        $this->createInitialEventAccessPolicyAction->handle($eventId, $registrationRequired);
    }

    public function applyChangeAnnouncementMutations(string $eventId, string $type, array $changes): array
    {
        return $this->applyChangeAnnouncementMutationsAction->handle($eventId, $type, $changes);
    }

    /**
     * @param  EventChangeAnnouncementAttributes  $attributes
     */
    public function createChangeAnnouncement(array $attributes): string
    {
        return $this->createEventChangeAnnouncementAction->handle($attributes);
    }

    public function applyContributionUpdate(string $eventId, array $state): array
    {
        return $this->applyEventContribution->handle($eventId, $state);
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

    /**
     * @param  list<string>  $eventIds
     */
    public function syncSearchableEventsByIds(array $eventIds): void
    {
        $events = Event::query()->whereKey($eventIds)->get();

        if ($events->isEmpty()) {
            return;
        }

        $searchable = $events->filter(fn (Event $event): bool => $event->shouldBeSearchable())->values();
        $unsearchable = $events->reject(fn (Event $event): bool => $event->shouldBeSearchable())->values();

        if ($searchable->isNotEmpty()) {
            $searchable->searchable();
        }

        if ($unsearchable->isNotEmpty()) {
            $unsearchable->unsearchable();
        }
    }

    public function personSlugSegmentsForPersonIds(array $personIds): array
    {
        return $this->generateEventSlug->personSlugSegmentsForPersonIds($personIds);
    }

    public function personSlugSegmentsForState(array $personIds, ?string $organizerKind, ?string $organizerId): array
    {
        return $this->generateEventSlug->personSlugSegmentsForState(
            $personIds,
            $organizerKind,
            $organizerId,
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

    public function ensureTaxonomyTerm(string $eventTaxonomyCode, string $name): string
    {
        return $this->ensureTaxonomyTerm->handle($eventTaxonomyCode, $name);
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
        $organizer = EventModel::query('institution')->find($organizerId)
            ?? EventModel::query('person')->find($organizerId);

        if ($organizer === null) {
            return;
        }

        $this->syncKeyPeople->syncOccurrenceOrganizer($occurrence, $organizer);
    }

    public function syncEventSchedule(string $eventId, EventScheduleInput $schedule): void
    {
        $this->syncScheduleFromInput->handle($eventId, $schedule);
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

    private function resolveEvent(string $eventId): Event
    {
        $event = EventModel::query('event')->withoutOwnerScope()->findOrFail($eventId);

        if (! $event instanceof Event) {
            throw new RuntimeException('Expected the resolved record to be an event.');
        }

        return $event;
    }
}
