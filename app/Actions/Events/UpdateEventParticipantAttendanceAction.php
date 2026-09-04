<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\Events\Models\EventAttendanceLog;
use AIArmada\Events\Models\EventRegistrationParticipant;
use App\Enums\EventAttendanceStatus;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\Registration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class UpdateEventParticipantAttendanceAction
{
    /**
     * Record the current attendance decision for one participant and scope.
     *
     * A missing row remains "not recorded yet". Only the two explicit states
     * are persisted, and every correction receives an immutable log entry.
     */
    public function handle(
        Event $event,
        EventRegistrationParticipant $participant,
        EventAttendanceStatus $status,
        User $actor,
        ?string $occurrenceId = null,
        ?string $sessionId = null,
        ?string $note = null,
        string $source = 'manual_attendance',
    ): EventCheckin {
        Gate::forUser($actor)->authorize('manageAttendance', $event);

        if ($status === EventAttendanceStatus::NotRecorded) {
            throw new InvalidArgumentException('Not recorded is represented by the absence of an active attendance record.');
        }

        $this->assertParticipantBelongsToEvent($event, $participant);

        if ($sessionId !== null) {
            $this->assertSessionBelongsToScope($event, $occurrenceId, $sessionId);
        } elseif ($occurrenceId !== null) {
            $this->assertOccurrenceBelongsToEvent($event, $occurrenceId);
        }

        return DB::transaction(function () use (
            $actor,
            $event,
            $note,
            $occurrenceId,
            $participant,
            $sessionId,
            $source,
            $status,
        ): EventCheckin {
            $registration = Registration::query()
                ->whereKey($participant->event_registration_id)
                ->where('event_id', $event->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $attendance = $this->findCurrentAttendance($event, $participant, $occurrenceId, $sessionId);
            $previousStatus = $attendance instanceof EventCheckin
                ? $this->resolveStatus($attendance)
                : null;
            $now = CarbonImmutable::now();
            $metadata = $this->attendanceMetadata($attendance, $status, $actor, $now);

            if ($attendance instanceof EventCheckin) {
                $attendance->forceFill([
                    'attendance_type' => $status->value,
                    'checked_in_at' => $status === EventAttendanceStatus::Attended
                        ? ($attendance->checked_in_at ?? $now)
                        : null,
                    'check_in_source' => $source,
                    'verified_by_user_id' => (string) $actor->getKey(),
                    'notes' => $note,
                    'metadata' => $metadata,
                ])->save();
            } else {
                $attendance = EventCheckin::query()->create([
                    'event_id' => (string) $event->getKey(),
                    'event_occurrence_id' => $occurrenceId,
                    'event_session_id' => $sessionId,
                    'event_registration_id' => (string) $registration->getKey(),
                    'event_registration_participant_id' => (string) $participant->getKey(),
                    'pass_id' => $this->resolvePassId($participant, $occurrenceId, $sessionId),
                    'attendance_type' => $status->value,
                    'checked_in_at' => $status === EventAttendanceStatus::Attended ? $now : null,
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
                    'new_status' => $status->value,
                ],
            ]);

            return $attendance;
        });
    }

    /**
     * Remove the active attendance decision while preserving its audit trail.
     * The participant then returns to the default "not recorded" state.
     */
    public function clear(
        Event $event,
        EventRegistrationParticipant $participant,
        User $actor,
        ?string $occurrenceId = null,
        ?string $sessionId = null,
        ?string $note = null,
        string $source = 'manual_attendance',
    ): void {
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

            if (! $attendance instanceof EventCheckin) {
                return;
            }

            $now = CarbonImmutable::now();
            $previousStatus = $this->resolveStatus($attendance);
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
        });
    }

    private function assertParticipantBelongsToEvent(
        Event $event,
        EventRegistrationParticipant $participant,
    ): void {
        if ((string) $participant->event_id !== (string) $event->getKey()) {
            throw new InvalidArgumentException('The selected participant does not belong to this event.');
        }
    }

    private function assertOccurrenceBelongsToEvent(Event $event, string $occurrenceId): void
    {
        if (! $event->occurrences()->whereKey($occurrenceId)->exists()) {
            throw new InvalidArgumentException('The selected occurrence does not belong to this event.');
        }
    }

    private function assertSessionBelongsToScope(Event $event, ?string $occurrenceId, string $sessionId): void
    {
        $session = $event->sessions()
            ->whereKey($sessionId)
            ->first();

        if ($session === null || ($occurrenceId !== null && (string) $session->event_occurrence_id !== $occurrenceId)) {
            throw new InvalidArgumentException('The selected session does not belong to this event occurrence.');
        }
    }

    private function findCurrentAttendance(
        Event $event,
        EventRegistrationParticipant $participant,
        ?string $occurrenceId,
        ?string $sessionId,
    ): ?EventCheckin {
        return EventCheckin::query()
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

    private function resolveStatus(EventCheckin $attendance): ?string
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
        ?EventCheckin $attendance,
        EventAttendanceStatus $status,
        User $actor,
        CarbonImmutable $now,
    ): array {
        $metadata = $attendance instanceof EventCheckin && is_array($attendance->metadata)
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
