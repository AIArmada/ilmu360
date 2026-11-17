<?php

namespace App\Features\Events\Actions\Changes;

use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\EventChangePublisher;
use App\Features\ShareTracking\Api\ParticipationOutcomeReversals;
use App\Models\Event;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Events\Enums\EventChangeType;
use App\Shared\Events\EventChangeNoticeNotifier;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;
use ValueError;

final readonly class PublishEventChangeAnnouncementAction implements EventChangePublisher
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public function publish(
        string $eventId,
        string $actorId,
        EventChangeType|string $type,
        ?string $publicMessage,
        ?string $internalNote = null,
        ?string $replacementEventId = null,
        array $changes = [],
        bool $notify = true,
        ?string $severity = null,
    ): string {
        $event = Event::query()->withoutOwnerScope()->findOrFail($eventId);
        $actor = MorphModelResolver::resolve('user')::query()->findOrFail($actorId);
        abort_unless($actor instanceof Authenticatable, 404);
        if (! Gate::forUser($actor)->allows('publishChange', $event)) {
            throw new AuthorizationException;
        }
        $replacementEvent = $replacementEventId !== null
            ? Event::query()->withoutOwnerScope()->findOrFail($replacementEventId)
            : null;

        $type = $type instanceof EventChangeType
            ? $type
            : EventChangeType::from($type);

        $severity = is_string($severity) && $severity !== ''
            ? $severity
            : $this->defaultSeverity($event, $type, $changes);
        if (! in_array($severity, ['info', 'high', 'urgent'], true)) {
            throw new ValueError("Invalid event change severity [{$severity}].");
        }

        $this->validateReplacementEvent($event, $replacementEvent);

        $announcementId = DB::transaction(function () use ($event, $actor, $type, $publicMessage, $internalNote, $replacementEvent, $changes, $severity): string {
            $event->refresh();
            $event->loadMissing($this->snapshotRelations());

            $beforeSnapshot = $this->snapshot($event);
            $changedFields = app(EventAuthoring::class)->applyChangeAnnouncementMutations(
                (string) $event->getKey(),
                $type->value,
                $changes,
            );
            $event->refresh();
            $event->loadMissing($this->snapshotRelations());

            // A published cancellation is a canonical event cancellation:
            // participation outcomes stop qualifying in the same
            // transaction. Occurrence-scoped rows were already reversed by
            // the schedule listener; this covers event-level rows.
            if ($type === EventChangeType::Cancelled) {
                app(ParticipationOutcomeReversals::class)->reverse(
                    'event',
                    (string) $event->getKey(),
                    (string) $actor->getKey(),
                    'event_cancelled',
                );
            }

            $afterSnapshot = $this->snapshot($event);
            $changedFields = array_values(array_unique(array_filter($changedFields)));

            $message = $this->publicMessage($type, $publicMessage, $replacementEvent);

            $announcementId = app(EventAuthoring::class)->createChangeAnnouncement([
                'event_id' => (string) $event->getKey(),
                'replacement_event_id' => $replacementEvent instanceof Event ? (string) $replacementEvent->getKey() : null,
                'created_by_type' => $actor->getMorphClass(),
                'created_by_id' => (string) $actor->getKey(),
                'update_type' => $type->value,
                'severity' => $severity,
                'message' => $message,
                'title' => $message,
                'notes' => $internalNote,
                'metadata' => [
                    'changed_fields' => $changedFields,
                    'before_snapshot' => $beforeSnapshot,
                    'after_snapshot' => $afterSnapshot,
                ],
                'published_at' => now()->toDateTimeString(),
                'visibility' => 'public',
            ]);

            if ($event->shouldBeSearchable()) {
                $event->searchable();
            } else {
                $event->unsearchable();
            }

            return $announcementId;
        });

        if ($notify) {
            app(EventChangeNoticeNotifier::class)->notify($announcementId);
        }

        return $announcementId;
    }

    /**
     * @throws ValidationException
     */
    private function validateReplacementEvent(Event $event, ?Event $replacementEvent): void
    {
        if (! $replacementEvent instanceof Event) {
            return;
        }

        if ((string) $event->getKey() !== (string) $replacementEvent->getKey()) {
            if (! $this->isReplacementEventPubliclyReachable($replacementEvent)) {
                throw ValidationException::withMessages([
                    'replacement_event_id' => __('The replacement event must be active and publicly reachable.'),
                ]);
            }

            if (! $this->replacementChainContains($replacementEvent, (string) $event->getKey())) {
                return;
            }

            throw ValidationException::withMessages([
                'replacement_event_id' => __('This replacement would create a loop. Choose the latest replacement event instead.'),
            ]);
        }

        throw ValidationException::withMessages([
            'replacement_event_id' => __('An event cannot replace itself.'),
        ]);
    }

    private function isReplacementEventPubliclyReachable(Event $event): bool
    {
        return $event->isPubliclyReachable();
    }

    private function replacementChainContains(Event $replacementEvent, string $eventId): bool
    {
        /** @var array<string, true> $visited */
        $visited = [];
        $current = $replacementEvent;

        while (! isset($visited[(string) $current->getKey()])) {
            if ((string) $current->getKey() === $eventId) {
                return true;
            }

            $visited[(string) $current->getKey()] = true;

            $nextAnnouncement = $current->latestPublishedReplacementAnnouncement()
                ->with('replacementEvent')
                ->first();

            $nextReplacement = $nextAnnouncement?->replacementEvent;

            if (! $nextReplacement instanceof Event) {
                return false;
            }

            $current = $nextReplacement;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function defaultSeverity(Event $event, EventChangeType $type, array $changes): string
    {
        if (in_array($type, [EventChangeType::Cancelled, EventChangeType::RescheduledEarlier], true)) {
            return 'urgent';
        }

        $changedStartsAt = $this->changedStartsAt($event, $changes);
        $currentStartsAt = $event->starts_at;

        if ($changedStartsAt instanceof CarbonInterface) {
            if ($currentStartsAt instanceof CarbonInterface && $changedStartsAt->lessThan($currentStartsAt)) {
                return 'urgent';
            }

            if ($changedStartsAt->isBetween(now(), now()->addHours(24))) {
                return 'urgent';
            }
        }

        $startsAt = $event->starts_at;

        if ($startsAt instanceof CarbonInterface && $startsAt->isBetween(now(), now()->addHours(24))) {
            return 'urgent';
        }

        if (array_intersect(array_keys($changes), ['starts_at', 'ends_at', 'timezone', 'venue_id', 'space_id'])) {
            return 'high';
        }

        return match ($type) {
            EventChangeType::LocationChanged,
            EventChangeType::PersonChanged,
            EventChangeType::TopicChanged,
            EventChangeType::ReferenceChanged,
            EventChangeType::OrganizerChanged,
            EventChangeType::ReplacementLinked,
            EventChangeType::Postponed,
            EventChangeType::RescheduledLater,
            EventChangeType::ScheduleChanged => 'high',
            default => 'info',
        };
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function changedStartsAt(Event $event, array $changes): ?CarbonInterface
    {
        if (! array_key_exists('starts_at', $changes) || blank($changes['starts_at'])) {
            return null;
        }

        $value = $changes['starts_at'];

        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse(
                $value,
                is_string($event->timezone) && $event->timezone !== '' ? $event->timezone : null,
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function publicMessage(EventChangeType $type, ?string $message, ?Event $replacementEvent): string
    {
        $message = trim((string) $message);

        if ($message !== '') {
            return $message;
        }

        if ($replacementEvent instanceof Event) {
            return __('This event has an important update. Please use the linked replacement event for the latest details.');
        }

        return match ($type) {
            EventChangeType::Cancelled => __('This event has been cancelled.'),
            EventChangeType::Postponed => __('This event has been postponed. The new date is not confirmed yet.'),
            EventChangeType::RescheduledEarlier,
            EventChangeType::RescheduledLater,
            EventChangeType::ScheduleChanged => __('The event schedule has changed. Please check the latest date and time.'),
            EventChangeType::LocationChanged => __('The event location has changed. Please check the latest venue details.'),
            EventChangeType::PersonChanged => __('The event person details have changed.'),
            EventChangeType::TopicChanged => __('The event topic has changed.'),
            EventChangeType::ReferenceChanged => __('The event reference details have changed.'),
            EventChangeType::OrganizerChanged => __('The event organizer details have changed.'),
            EventChangeType::ReplacementLinked => __('A replacement event has been linked. Please use the replacement event for the latest details.'),
            EventChangeType::Other => __('Important event details have been updated.'),
        };
    }

    /**
     * @return list<string>
     */
    private function snapshotRelations(): array
    {
        return [
            'institution:id,name,slug',
            'venue:id,name,slug',
            'primaryLocation.venueSpace:id,name,slug',
            'personKeyPeople.person:id,name,slug',
            'references.parentReference.parentReference',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function spaceName(Event $event): ?string
    {
        $location = $event->primaryLocation;
        if ($location === null) {
            return null;
        }

        $snapshot = trim((string) ($location->space_name_snapshot ?? ''));

        return $snapshot !== '' ? $snapshot : $location->venueSpace?->name;
    }

    private function snapshot(Event $event): array
    {
        return [
            'id' => (string) $event->getKey(),
            'title' => $event->title,
            'slug' => $event->slug,
            'status' => (string) $event->status,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'timezone' => $event->timezone,
            'institution' => $event->institution === null ? null : [
                'id' => (string) $event->institution->getKey(),
                'name' => $event->institution->name,
                'slug' => $event->institution->slug,
            ],
            'venue' => $event->venue === null ? null : [
                'id' => (string) $event->venue->getKey(),
                'name' => $event->venue->name,
                'slug' => $event->venue->slug,
            ],
            'space' => $event->primaryLocation?->venueSpace === null ? null : [
                'id' => (string) $event->primaryLocation->venueSpace->getKey(),
                'name' => $this->spaceName($event),
            ],
            'persons' => $event->personKeyPeople
                ->map(static function (Model $keyPerson): array {
                    $person = $keyPerson->getRelationValue('person');

                    return [
                        'id' => $person instanceof Model ? (string) $person->getKey() : null,
                        'name' => $person instanceof Model ? $person->getAttribute('name') : $keyPerson->getAttribute('display_name'),
                        'slug' => $person instanceof Model ? $person->getAttribute('slug') : null,
                    ];
                })
                ->values()
                ->all(),
            'references' => $event->references
                ->map(fn (Model $reference): array => [
                    'id' => (string) $reference->getKey(),
                    'title' => $reference->getAttribute('display_title'),
                    'slug' => $reference->slug,
                ])
                ->values()
                ->all(),
            'links' => [
                'event_url' => $event->event_url,
                'live_url' => $event->live_url,
                'recording_url' => $event->recording_url,
            ],
        ];
    }
}
