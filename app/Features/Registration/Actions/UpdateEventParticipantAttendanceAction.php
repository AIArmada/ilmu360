<?php

declare(strict_types=1);

namespace App\Features\Registration\Actions;

use AIArmada\Events\Models\Event as PackageEvent;
use AIArmada\Events\Models\EventAttendance;
use AIArmada\Events\Models\EventAttendanceLog;
use AIArmada\Events\Models\EventRegistration;
use AIArmada\Events\Models\EventRegistrationParticipant;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Events\Enums\EventAttendanceStatus;
use App\Shared\ShareTracking\ParticipationAttribution;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Owns the staff attendance decision for one participant and scope.
 *
 * A missing row remains "not recorded yet". Only the two explicit states are
 * persisted, and every correction receives an immutable log entry. Caller
 * identities arrive as scalars and resolve through morph aliases so the
 * feature does not import root models, services or enums.
 */
final readonly class UpdateEventParticipantAttendanceAction
{
    public function __construct(
        private ParticipationAttribution $attribution,
    ) {}

    /**
     * Record the current attendance decision for one participant and scope.
     */
    public function handle(
        string $eventId,
        string $participantId,
        string $status,
        string $actorId,
        ?string $occurrenceId = null,
        ?string $sessionId = null,
        ?string $note = null,
        string $source = 'manual_attendance',
    ): string {
        [$event, $participant, $actor] = $this->resolveContext($eventId, $participantId, $actorId);

        Gate::forUser($actor)->authorize('manageAttendance', $event);

        $attendanceStatus = $this->resolveStatus($status);

        $this->assertParticipantBelongsToEvent($event, $participant);

        if ($sessionId !== null) {
            $this->assertSessionBelongsToScope($event, $occurrenceId, $sessionId);
        } elseif ($occurrenceId !== null) {
            $this->assertOccurrenceBelongsToEvent($event, $occurrenceId);
        }

        return DB::transaction(function () use (
            $actor,
            $attendanceStatus,
            $event,
            $note,
            $occurrenceId,
            $participant,
            $sessionId,
            $source,
        ): string {
            /** @var class-string<EventRegistration> $registrationClass */
            $registrationClass = MorphModelResolver::resolve('registration');

            $registration = $registrationClass::query()
                ->whereKey($participant->event_registration_id)
                ->where('event_id', $event->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $attendance = $this->findCurrentAttendance($event, $participant, $occurrenceId, $sessionId);
            $previousStatus = $attendance instanceof EventAttendance
                ? $this->resolveStatusValue($attendance)
                : null;
            $now = CarbonImmutable::now();
            $metadata = $this->attendanceMetadata($attendance, $attendanceStatus, $actor, $now);

            if ($attendance instanceof EventAttendance) {
                $attendance->forceFill([
                    'attendance_type' => $attendanceStatus->value,
                    'checked_in_at' => $attendanceStatus === EventAttendanceStatus::Attended
                        ? ($attendance->checked_in_at ?? $now)
                        : null,
                    'check_in_source' => $source,
                    'verified_by_user_id' => (string) $actor->getKey(),
                    'notes' => $note,
                    'metadata' => $metadata,
                ])->save();
            } else {
                /** @var class-string<EventAttendance> $checkinClass */
                $checkinClass = MorphModelResolver::resolve('checkin');

                $attendance = $checkinClass::query()->create([
                    'event_id' => (string) $event->getKey(),
                    'event_occurrence_id' => $occurrenceId,
                    'event_session_id' => $sessionId,
                    'event_registration_id' => (string) $registration->getKey(),
                    'event_registration_participant_id' => (string) $participant->getKey(),
                    'pass_id' => $this->resolvePassId($participant, $occurrenceId, $sessionId),
                    'attendance_type' => $attendanceStatus->value,
                    'checked_in_at' => $attendanceStatus === EventAttendanceStatus::Attended ? $now : null,
                    'check_in_source' => $source,
                    'verified_by_user_id' => (string) $actor->getKey(),
                    'notes' => $note,
                    'metadata' => $metadata,
                ]);
            }

            EventAttendanceLog::query()->create([
                'event_attendance_id' => (string) $attendance->getKey(),
                'action' => 'attendance_updated',
                'source' => $source,
                'performed_by_type' => $actor->getMorphClass(),
                'performed_by_id' => (string) $actor->getKey(),
                'occurred_at' => $now,
                'notes' => $note,
                'metadata' => [
                    'event_id' => (string) $event->getKey(),
                    'event_occurrence_id' => $occurrenceId,
                    'event_session_id' => $sessionId,
                    'previous_status' => $previousStatus,
                    'new_status' => $attendanceStatus->value,
                ],
            ]);

            // A correction from a recorded attendance to did-not-attend
            // invalidates the check-in outcome in the same transaction.
            // Rows that never credited (unattributed receipts, missing
            // conversions) reverse to a harmless no-op.
            if ($attendanceStatus === EventAttendanceStatus::DidNotAttend
                && $previousStatus === EventAttendanceStatus::Attended->value) {
                $this->attribution->reverseCheckIn(
                    checkinId: (string) $attendance->getKey(),
                    reason: 'attendance_corrected',
                    actorId: (string) $actor->getKey(),
                );
            }

            return (string) $attendance->getKey();
        });
    }

    /**
     * Remove the active attendance decision while preserving its audit trail.
     * The participant then returns to the default "not recorded" state.
     */
    public function clear(
        string $eventId,
        string $participantId,
        string $actorId,
        ?string $occurrenceId = null,
        ?string $sessionId = null,
        ?string $note = null,
        string $source = 'manual_attendance',
    ): void {
        [$event, $participant, $actor] = $this->resolveContext($eventId, $participantId, $actorId);

        Gate::forUser($actor)->authorize('manageAttendance', $event);

        $this->assertParticipantBelongsToEvent($event, $participant);

        if ($sessionId !== null) {
            $this->assertSessionBelongsToScope($event, $occurrenceId, $sessionId);
        } elseif ($occurrenceId !== null) {
            $this->assertOccurrenceBelongsToEvent($event, $occurrenceId);
        }

        DB::transaction(function () use (
            $actor,
            $event,
            $note,
            $occurrenceId,
            $participant,
            $sessionId,
            $source,
        ): void {
            $attendance = $this->findCurrentAttendance($event, $participant, $occurrenceId, $sessionId);

            if (! $attendance instanceof EventAttendance) {
                return;
            }

            $now = CarbonImmutable::now();
            $previousStatus = $this->resolveStatusValue($attendance);
            $metadata = is_array($attendance->metadata) ? $attendance->metadata : [];
            Arr::set($metadata, 'attendance.cleared_at', $now->toIso8601String());
            Arr::set($metadata, 'attendance.cleared_by_id', (string) $actor->getKey());

            $attendance->forceFill([
                'cancelled_at' => $now,
                'corrected_at' => $now,
                'check_in_source' => $source,
                'verified_by_user_id' => (string) $actor->getKey(),
                'notes' => $note,
                'metadata' => $metadata,
            ])->save();

            EventAttendanceLog::query()->create([
                'event_attendance_id' => (string) $attendance->getKey(),
                'action' => 'attendance_cleared',
                'source' => $source,
                'performed_by_type' => $actor->getMorphClass(),
                'performed_by_id' => (string) $actor->getKey(),
                'occurred_at' => $now,
                'notes' => $note,
                'metadata' => [
                    'event_id' => (string) $event->getKey(),
                    'event_occurrence_id' => $occurrenceId,
                    'event_session_id' => $sessionId,
                    'previous_status' => $previousStatus,
                    'new_status' => EventAttendanceStatus::NotRecorded->value,
                ],
            ]);

            // Clearing a recorded attendance invalidates its check-in
            // outcome in the same transaction. Clearing a did-not-attend
            // row that never credited reverses to a harmless no-op.
            if ($previousStatus === EventAttendanceStatus::Attended->value) {
                $this->attribution->reverseCheckIn(
                    checkinId: (string) $attendance->getKey(),
                    reason: 'attendance_cleared',
                    actorId: (string) $actor->getKey(),
                );
            }
        });
    }

    /**
     * @return array{0: PackageEvent, 1: EventRegistrationParticipant, 2: Model&Authenticatable}
     */
    private function resolveContext(
        string $eventId,
        string $participantId,
        string $actorId,
    ): array {
        $eventClass = MorphModelResolver::resolve('event');
        $participantClass = MorphModelResolver::resolve('event_registration_participant');
        $userClass = MorphModelResolver::resolve('user');

        /** @var PackageEvent $event */
        $event = $eventClass::query()->findOrFail($eventId);

        /** @var EventRegistrationParticipant $participant */
        $participant = $participantClass::query()->findOrFail($participantId);

        /** @var Model&Authenticatable $actor */
        $actor = $userClass::query()->findOrFail($actorId);

        return [$event, $participant, $actor];
    }

    private function resolveStatus(string $status): EventAttendanceStatus
    {
        $attendanceStatus = EventAttendanceStatus::tryFrom($status);

        if (! $attendanceStatus instanceof EventAttendanceStatus) {
            throw new InvalidArgumentException('The selected attendance status is invalid.');
        }

        if ($attendanceStatus === EventAttendanceStatus::NotRecorded) {
            throw new InvalidArgumentException('Not recorded is represented by the absence of an active attendance record.');
        }

        return $attendanceStatus;
    }

    private function assertParticipantBelongsToEvent(
        PackageEvent $event,
        EventRegistrationParticipant $participant,
    ): void {
        if ((string) $participant->event_id !== (string) $event->getKey()) {
            throw new InvalidArgumentException('The selected participant does not belong to this event.');
        }
    }

    private function assertOccurrenceBelongsToEvent(PackageEvent $event, string $occurrenceId): void
    {
        if (! $event->occurrences()->whereKey($occurrenceId)->exists()) {
            throw new InvalidArgumentException('The selected occurrence does not belong to this event.');
        }
    }

    private function assertSessionBelongsToScope(PackageEvent $event, ?string $occurrenceId, string $sessionId): void
    {
        $session = $event->sessions()
            ->whereKey($sessionId)
            ->first();

        if ($session === null || ($occurrenceId !== null && (string) $session->event_occurrence_id !== $occurrenceId)) {
            throw new InvalidArgumentException('The selected session does not belong to this event occurrence.');
        }
    }

    private function findCurrentAttendance(
        PackageEvent $event,
        EventRegistrationParticipant $participant,
        ?string $occurrenceId,
        ?string $sessionId,
    ): ?EventAttendance {
        /** @var class-string<EventAttendance> $checkinClass */
        $checkinClass = MorphModelResolver::resolve('checkin');

        return $checkinClass::query()
            ->where('event_id', $event->getKey())
            ->where('event_registration_participant_id', $participant->getKey())
            ->whereNull('cancelled_at')
            ->when(
                $occurrenceId === null,
                fn ($query) => $query->whereNull('event_occurrence_id'),
                fn ($query) => $query->where('event_occurrence_id', $occurrenceId),
            )
            ->when(
                $sessionId === null,
                fn ($query) => $query->whereNull('event_session_id'),
                fn ($query) => $query->where('event_session_id', $sessionId),
            )
            ->latest('created_at')
            ->first();
    }

    private function resolveStatusValue(EventAttendance $attendance): ?string
    {
        if ($attendance->attendance_type === EventAttendanceStatus::DidNotAttend->value) {
            return EventAttendanceStatus::DidNotAttend->value;
        }

        if ($attendance->attendance_type === EventAttendanceStatus::Attended->value || $attendance->checked_in_at !== null) {
            return EventAttendanceStatus::Attended->value;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function attendanceMetadata(
        ?EventAttendance $attendance,
        EventAttendanceStatus $status,
        Model&Authenticatable $actor,
        CarbonImmutable $now,
    ): array {
        $metadata = $attendance instanceof EventAttendance && is_array($attendance->metadata)
            ? $attendance->metadata
            : [];

        if ($attendance?->checked_in_at !== null) {
            Arr::set($metadata, 'attendance.original_checked_in_at', $attendance->checked_in_at->toIso8601String());
        }

        Arr::set($metadata, 'attendance.status', $status->value);
        Arr::set($metadata, 'attendance.updated_at', $now->toIso8601String());
        Arr::set($metadata, 'attendance.updated_by_id', (string) $actor->getKey());

        return $metadata;
    }

    private function resolvePassId(
        EventRegistrationParticipant $participant,
        ?string $occurrenceId,
        ?string $sessionId,
    ): ?string {
        $query = $participant->passes();

        if ($sessionId !== null) {
            $query->where('session_id', $sessionId);
        } elseif ($occurrenceId !== null) {
            $query->where('occurrence_id', $occurrenceId);
        }

        $pass = $query->first();

        return $pass?->getKey() !== null ? (string) $pass->getKey() : null;
    }
}
