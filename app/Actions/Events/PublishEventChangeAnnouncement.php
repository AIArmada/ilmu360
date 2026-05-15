<?php

namespace App\Actions\Events;

use App\Enums\EventChangeSeverity;
use App\Enums\EventChangeStatus;
use App\Enums\EventChangeType;
use App\Enums\ScheduleState;
use App\Models\Event;
use App\Models\EventChangeAnnouncement;
use App\Models\EventKeyPerson;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use App\Services\Notifications\EventNotificationService;
use App\States\EventStatus\Cancelled;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

class PublishEventChangeAnnouncement
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $changes
     */
    public function handle(
        Event $event,
        User $actor,
        EventChangeType|string $type,
        ?string $publicMessage,
        ?string $internalNote = null,
        ?Event $replacementEvent = null,
        array $changes = [],
        bool $notify = true,
        EventChangeSeverity|string|null $severity = null,
    ): EventChangeAnnouncement {
        if (! $actor->can('publishChange', $event)) {
            throw new AuthorizationException;
        }

        $type = $type instanceof EventChangeType
            ? $type
            : EventChangeType::from($type);

        $severity = $severity instanceof EventChangeSeverity
            ? $severity
            : (is_string($severity) && $severity !== ''
                ? EventChangeSeverity::from($severity)
                : $this->defaultSeverity($event, $type, $changes));

        $this->validateReplacementEvent($event, $replacementEvent);

        $announcement = DB::transaction(function () use ($event, $actor, $type, $publicMessage, $internalNote, $replacementEvent, $changes, $severity): EventChangeAnnouncement {
            $event->refresh();
            $event->loadMissing($this->snapshotRelations());

            $beforeSnapshot = $this->snapshot($event);
            $changedFields = $this->applyEventMutations($event, $type, $changes);

            $event->save();
            $event->refresh();
            $event->loadMissing($this->snapshotRelations());

            $afterSnapshot = $this->snapshot($event);
            $changedFields = array_values(array_unique(array_filter($changedFields)));

            $announcement = EventChangeAnnouncement::query()->create([
                'event_id' => $event->id,
                'replacement_event_id' => $replacementEvent?->id,
                'actor_id' => $actor->id,
                'type' => $type,
                'status' => EventChangeStatus::Published,
                'severity' => $severity,
                'public_message' => $this->publicMessage($type, $publicMessage, $replacementEvent),
                'internal_note' => $internalNote,
                'changed_fields' => $changedFields,
                'before_snapshot' => $beforeSnapshot,
                'after_snapshot' => $afterSnapshot,
                'published_at' => now(),
            ]);

            if ($event->shouldBeSearchable()) {
                $event->searchable();
            } else {
                $event->unsearchable();
            }

            return $announcement;
        });

        if ($notify) {
            app(EventNotificationService::class)->notifyEventChangeAnnouncement(
                $announcement->fresh(['event', 'replacementEvent']) ?? $announcement,
            );
        }

        return $announcement;
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
     * @return list<string>
     */
    private function applyEventMutations(Event $event, EventChangeType $type, array $changes): array
    {
        $changedFields = [];

        foreach ($this->supportedScalarFields() as $field) {
            if (! array_key_exists($field, $changes)) {
                continue;
            }

            $event->{$field} = $changes[$field];
            $changedFields[] = $field;
        }

        if ($type === EventChangeType::Cancelled) {
            $event->status = Cancelled::class;
            $event->schedule_state = ScheduleState::Cancelled;
            $changedFields[] = 'status';
            $changedFields[] = 'schedule_state';
        }

        if ($type === EventChangeType::Postponed) {
            $event->schedule_state = ScheduleState::Postponed;
            $changedFields[] = 'schedule_state';
        }

        if (in_array($type, [
            EventChangeType::RescheduledEarlier,
            EventChangeType::RescheduledLater,
            EventChangeType::ScheduleChanged,
        ], true)) {
            $event->schedule_state = ScheduleState::Active;
            $changedFields[] = 'schedule_state';
        }

        return $changedFields;
    }

    /**
     * @return list<string>
     */
    private function supportedScalarFields(): array
    {
        return [
            'title',
            'starts_at',
            'ends_at',
            'timezone',
            'institution_id',
            'venue_id',
            'space_id',
            'event_url',
            'live_url',
            'recording_url',
            'organizer_type',
            'organizer_id',
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function defaultSeverity(Event $event, EventChangeType $type, array $changes): EventChangeSeverity
    {
        if (in_array($type, [EventChangeType::Cancelled, EventChangeType::RescheduledEarlier], true)) {
            return EventChangeSeverity::Urgent;
        }

        $changedStartsAt = $this->changedStartsAt($event, $changes);
        $currentStartsAt = $event->starts_at;

        if ($changedStartsAt instanceof CarbonInterface) {
            if ($currentStartsAt instanceof CarbonInterface && $changedStartsAt->lessThan($currentStartsAt)) {
                return EventChangeSeverity::Urgent;
            }

            if ($changedStartsAt->isBetween(now(), now()->addHours(24))) {
                return EventChangeSeverity::Urgent;
            }
        }

        $startsAt = $event->starts_at;

        if ($startsAt instanceof CarbonInterface && $startsAt->isBetween(now(), now()->addHours(24))) {
            return EventChangeSeverity::Urgent;
        }

        if (array_intersect(array_keys($changes), ['starts_at', 'ends_at', 'timezone', 'venue_id', 'space_id'])) {
            return EventChangeSeverity::High;
        }

        return match ($type) {
            EventChangeType::LocationChanged,
            EventChangeType::SpeakerChanged,
            EventChangeType::TopicChanged,
            EventChangeType::ReferenceChanged,
            EventChangeType::OrganizerChanged,
            EventChangeType::ReplacementLinked,
            EventChangeType::Postponed,
            EventChangeType::RescheduledLater,
            EventChangeType::ScheduleChanged => EventChangeSeverity::High,
            default => EventChangeSeverity::Info,
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
            EventChangeType::SpeakerChanged => __('The event speaker details have changed.'),
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
            'space:id,name,slug',
            'speakerKeyPeople.speaker:id,name,slug',
            'references:id,title,slug',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Event $event): array
    {
        return [
            'id' => (string) $event->getKey(),
            'title' => $event->title,
            'slug' => $event->slug,
            'status' => (string) $event->status,
            'schedule_state' => $this->scheduleStateValue($event),
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
            'space' => $event->space === null ? null : [
                'id' => (string) $event->space->getKey(),
                'name' => $event->space->name,
                'slug' => $event->space->slug,
            ],
            'speakers' => $event->speakerKeyPeople
                ->map(fn (EventKeyPerson $keyPerson): array => [
                    'id' => $keyPerson->speaker instanceof Speaker ? (string) $keyPerson->speaker->getKey() : null,
                    'name' => $keyPerson->speaker instanceof Speaker ? $keyPerson->speaker->name : $keyPerson->name,
                    'slug' => $keyPerson->speaker instanceof Speaker ? $keyPerson->speaker->slug : null,
                ])
                ->values()
                ->all(),
            'references' => $event->references
                ->map(fn (Reference $reference): array => [
                    'id' => (string) $reference->getKey(),
                    'title' => $reference->title,
                    'slug' => $reference->slug,
                ])
                ->values()
                ->all(),
            'links' => Arr::only($event->getAttributes(), ['event_url', 'live_url', 'recording_url']),
        ];
    }

    private function scheduleStateValue(Event $event): ?string
    {
        $scheduleState = $event->schedule_state;

        if ($scheduleState instanceof ScheduleState) {
            return $scheduleState->value;
        }

        return is_string($scheduleState) && $scheduleState !== '' ? $scheduleState : null;
    }
}
