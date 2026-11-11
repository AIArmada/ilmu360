<?php

declare(strict_types=1);

namespace App\Features\Registration\Actions;

use AIArmada\Events\Contracts\EventCheckInService;
use AIArmada\Events\Models\Event as PackageEvent;
use AIArmada\Events\Models\EventAttendance;
use AIArmada\Events\Models\EventRegistration;
use AIArmada\Events\Models\EventRegistrationParticipant;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Events\Enums\EventAttendanceStatus;
use App\Shared\ShareTracking\ParticipationAttribution;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Owns the staff-assisted participant check-in write path.
 *
 * The package EventCheckInService remains the canonical external attendance
 * writer for occurrence/session check-ins. Event-scope attendance is the
 * overall staff record and is written by UpdateEventParticipantAttendanceAction
 * instead of being silently attached to the primary occurrence. Caller
 * identities arrive as scalars and resolve through morph aliases so the
 * feature does not import root models, services or enums.
 */
final readonly class RecordEventParticipantCheckInAction
{
    public function __construct(
        private EventCheckInService $checkIns,
        private UpdateEventParticipantAttendanceAction $updateAttendance,
        private ParticipationAttribution $attribution,
    ) {}

    /**
     * @return array{status: 'created'|'duplicate', checkin_id: string}
     */
    public function handle(
        string $eventId,
        string $participantId,
        string $staffId,
        ?string $occurrenceId = null,
        ?string $sessionId = null,
    ): array {
        $eventClass = MorphModelResolver::resolve('event');
        $participantClass = MorphModelResolver::resolve('event_registration_participant');
        $userClass = MorphModelResolver::resolve('user');

        /** @var PackageEvent $event */
        $event = $eventClass::query()->findOrFail($eventId);

        /** @var EventRegistrationParticipant $participant */
        $participant = $participantClass::query()->findOrFail($participantId);

        /** @var Model&Authenticatable $staff */
        $staff = $userClass::query()->findOrFail($staffId);

        Gate::forUser($staff)->authorize('manageAttendance', $event);

        if ((string) $participant->event_id !== (string) $event->getKey()) {
            throw new InvalidArgumentException('The selected participant does not belong to this event.');
        }

        /** @var class-string<EventRegistration> $registrationClass */
        $registrationClass = MorphModelResolver::resolve('registration');

        $registration = $registrationClass::query()
            ->whereKey($participant->event_registration_id)
            ->where('event_id', $event->getKey())
            ->firstOrFail();

        // The package check-in service intentionally works at occurrence or
        // session scope. An event-level staff action is the overall attendance
        // record and must not be silently attached to the primary occurrence.
        if ($sessionId === null && $occurrenceId === null) {
            $existingAttendance = $this->findActiveEventScopeAttendance($event, $participant);

            if ($existingAttendance instanceof EventAttendance
                && $existingAttendance->attendance_type !== EventAttendanceStatus::DidNotAttend->value
                && ($existingAttendance->attendance_type === EventAttendanceStatus::Attended->value
                    || $existingAttendance->checked_in_at !== null)) {
                return ['status' => 'duplicate', 'checkin_id' => (string) $existingAttendance->getKey()];
            }

            $checkinId = DB::transaction(function () use ($event, $participant, $staff, $registration): string {
                $checkinId = $this->updateAttendance->handle(
                    eventId: (string) $event->getKey(),
                    participantId: (string) $participant->getKey(),
                    status: EventAttendanceStatus::Attended->value,
                    actorId: (string) $staff->getKey(),
                    source: 'staff_manual',
                );

                $this->attribution->recordStaffCheckIn(
                    checkinId: $checkinId,
                    eventId: (string) $event->getKey(),
                    staffId: (string) $staff->getKey(),
                    registrationId: (string) $registration->getKey(),
                );

                return $checkinId;
            });

            return ['status' => 'created', 'checkin_id' => $checkinId];
        }

        $occurrenceId ??= $sessionId !== null
            ? (string) $event->sessions()->whereKey($sessionId)->value('event_occurrence_id')
            : $this->resolveOccurrenceId($event, $registration, $participant);

        if ($occurrenceId === null || $occurrenceId === '') {
            throw new InvalidArgumentException('An event occurrence is required for check-in.');
        }

        if ($sessionId !== null && ! $event->sessions()->whereKey($sessionId)->where('event_occurrence_id', $occurrenceId)->exists()) {
            throw new InvalidArgumentException('The selected session does not belong to this event occurrence.');
        }

        $existingAbsence = $this->findActiveAbsence($event, $participant, $occurrenceId, $sessionId);

        if ($existingAbsence instanceof EventAttendance) {
            $checkinId = DB::transaction(function () use ($event, $participant, $staff, $occurrenceId, $sessionId, $existingAbsence, $registration): string {
                $checkinId = $this->updateAttendance->handle(
                    eventId: (string) $event->getKey(),
                    participantId: (string) $participant->getKey(),
                    status: EventAttendanceStatus::Attended->value,
                    actorId: (string) $staff->getKey(),
                    occurrenceId: $occurrenceId,
                    sessionId: $sessionId,
                    note: $existingAbsence->notes,
                    source: 'staff_manual',
                );

                $this->attribution->recordStaffCheckIn(
                    checkinId: $checkinId,
                    eventId: (string) $event->getKey(),
                    staffId: (string) $staff->getKey(),
                    registrationId: (string) $registration->getKey(),
                );

                return $checkinId;
            });

            return ['status' => 'created', 'checkin_id' => $checkinId];
        }

        return DB::transaction(function () use ($event, $participant, $registration, $occurrenceId, $sessionId, $staff): array {
            /** @var class-string<EventAttendance> $checkinClass */
            $checkinClass = MorphModelResolver::resolve('checkin');
            $passId = $this->resolvePassId($participant, $occurrenceId, $sessionId);
            $result = $this->checkIns->checkInWithResult([
                'event_id' => (string) $event->getKey(),
                'event_occurrence_id' => $occurrenceId,
                'event_session_id' => $sessionId,
                'event_registration_id' => (string) $registration->getKey(),
                'event_registration_participant_id' => (string) $participant->getKey(),
                'pass_id' => $passId,
                'attendance_type' => EventAttendanceStatus::Attended->value,
                'check_in_source' => 'staff_manual',
                'verified_by_user_id' => (string) $staff->getKey(),
                'performed_by_type' => $staff->getMorphClass(),
                'performed_by_id' => (string) $staff->getKey(),
                'metadata' => [
                    'staff_assisted' => true,
                ],
            ]);

            $checkin = $result->attendance instanceof $checkinClass
                ? $result->attendance
                : $checkinClass::query()->findOrFail($result->attendance->getKey());

            if ($result->created) {
                $checkin->refresh();

                $this->attribution->recordStaffCheckIn(
                    checkinId: (string) $checkin->getKey(),
                    eventId: (string) $event->getKey(),
                    staffId: (string) $staff->getKey(),
                    registrationId: (string) $registration->getKey(),
                );
            }

            return [
                'status' => $result->created ? 'created' : 'duplicate',
                'checkin_id' => (string) $checkin->getKey(),
            ];
        });
    }

    private function findActiveEventScopeAttendance(
        PackageEvent $event,
        EventRegistrationParticipant $participant,
    ): ?EventAttendance {
        /** @var class-string<EventAttendance> $checkinClass */
        $checkinClass = MorphModelResolver::resolve('checkin');

        return $checkinClass::query()
            ->where('event_id', $event->getKey())
            ->where('event_registration_participant_id', $participant->getKey())
            ->whereNull('event_occurrence_id')
            ->whereNull('event_session_id')
            ->whereNull('cancelled_at')
            ->latest('created_at')
            ->first();
    }

    private function findActiveAbsence(
        PackageEvent $event,
        EventRegistrationParticipant $participant,
        string $occurrenceId,
        ?string $sessionId,
    ): ?EventAttendance {
        /** @var class-string<EventAttendance> $checkinClass */
        $checkinClass = MorphModelResolver::resolve('checkin');

        return $checkinClass::query()
            ->where('event_id', $event->getKey())
            ->where('event_registration_participant_id', $participant->getKey())
            ->where('attendance_type', EventAttendanceStatus::DidNotAttend->value)
            ->whereNull('cancelled_at')
            ->where('event_occurrence_id', $occurrenceId)
            ->when(
                $sessionId === null,
                fn ($query) => $query->whereNull('event_session_id'),
                fn ($query) => $query->where('event_session_id', $sessionId),
            )
            ->latest('created_at')
            ->first();
    }

    private function resolveOccurrenceId(
        PackageEvent $event,
        EventRegistration $registration,
        EventRegistrationParticipant $participant,
    ): ?string {
        $occurrenceId = $participant->event_occurrence_id
            ?? $registration->event_occurrence_id
            ?? $event->primaryOccurrence()->value('id');

        return $occurrenceId !== null ? (string) $occurrenceId : null;
    }

    private function resolvePassId(
        EventRegistrationParticipant $participant,
        string $occurrenceId,
        ?string $sessionId,
    ): ?string {
        $query = $participant->passes();

        if ($sessionId !== null) {
            $query->where('session_id', $sessionId);
        } else {
            $query->where('occurrence_id', $occurrenceId);
        }

        $pass = $query->first() ?? $participant->passes()->first();

        return $pass?->getKey() !== null ? (string) $pass->getKey() : null;
    }
}
