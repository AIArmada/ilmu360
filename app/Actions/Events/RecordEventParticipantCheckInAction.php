<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\Events\Contracts\EventCheckInService;
use AIArmada\Events\Models\EventRegistrationParticipant;
use App\Enums\EventAttendanceStatus;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class RecordEventParticipantCheckInAction
{
    public function __construct(
        private readonly EventCheckInService $checkIns,
        private readonly UpdateEventParticipantAttendanceAction $updateAttendance,
    ) {}

    /**
     * @return array{status: 'created'|'duplicate', checkin: EventCheckin}
     */
    public function handle(
        Event $event,
        EventRegistrationParticipant $participant,
        User $staff,
        ?string $occurrenceId = null,
        ?string $sessionId = null,
    ): array {
        Gate::forUser($staff)->authorize('manageAttendance', $event);

        if ((string) $participant->event_id !== (string) $event->getKey()) {
            throw new InvalidArgumentException('The selected participant does not belong to this event.');
        }

        $registration = Registration::query()
            ->whereKey($participant->event_registration_id)
            ->where('event_id', $event->getKey())
            ->firstOrFail();

        // The package check-in service intentionally works at occurrence or
        // session scope. An event-level staff action is the overall attendance
        // record and must not be silently attached to the primary occurrence.
        if ($sessionId === null && $occurrenceId === null) {
            $existingAttendance = EventCheckin::query()
                ->where('event_id', $event->getKey())
                ->where('event_registration_participant_id', $participant->getKey())
                ->whereNull('event_occurrence_id')
                ->whereNull('event_session_id')
                ->whereNull('cancelled_at')
                ->latest('created_at')
                ->first();

            if ($existingAttendance instanceof EventCheckin
                && $existingAttendance->attendance_type !== EventAttendanceStatus::DidNotAttend->value
                && ($existingAttendance->attendance_type === EventAttendanceStatus::Attended->value
                    || $existingAttendance->checked_in_at !== null)) {
                return ['status' => 'duplicate', 'checkin' => $existingAttendance];
            }

            $checkin = $this->updateAttendance->handle(
                event: $event,
                participant: $participant,
                status: EventAttendanceStatus::Attended,
                actor: $staff,
                source: 'staff_manual',
            );

            return ['status' => 'created', 'checkin' => $checkin];
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

        $existingAbsence = EventCheckin::query()
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

        if ($existingAbsence instanceof EventCheckin) {
            $checkin = $this->updateAttendance->handle(
                event: $event,
                participant: $participant,
                status: EventAttendanceStatus::Attended,
                actor: $staff,
                occurrenceId: $occurrenceId,
                sessionId: $sessionId,
                note: $existingAbsence->notes,
                source: 'staff_manual',
            );

            return ['status' => 'created', 'checkin' => $checkin];
        }

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

        $checkin = $result->attendance instanceof EventCheckin
            ? $result->attendance
            : EventCheckin::query()->findOrFail($result->attendance->getKey());

        return [
            'status' => $result->created ? 'created' : 'duplicate',
            'checkin' => $checkin,
        ];
    }

    private function resolveOccurrenceId(
        Event $event,
        Registration $registration,
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
