<?php

declare(strict_types=1);

namespace App\Features\Registration\Queries;

use App\Features\Registration\Api\Data\CheckInSnapshot;
use App\Features\Registration\Api\Data\RegistrationSnapshot;
use App\Features\Registration\Models\EventCheckin;
use App\Features\Registration\Models\Registration;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/** Construct immutable Registration-owned source facts, never expose its models. */
final readonly class RegistrationSnapshotProjector
{
    public function registration(Registration $registration, bool $includeParticipant = false): RegistrationSnapshot
    {
        $event = $registration->event;
        $event = $event instanceof Model ? $event : null;
        $metadata = $registration->getAttribute('metadata');
        $metadata = is_array($metadata) ? $metadata : [];
        $primaryParticipantId = null;

        if ($includeParticipant) {
            $participant = $registration->participants()->where('is_primary', true)->first()
                ?? $registration->participants()->first();

            if ($participant instanceof Model) {
                $type = $this->scalar($participant->getAttribute('participant_type'));
                $id = $this->scalar($participant->getAttribute('participant_id'));

                if ($type !== null && $id !== null && in_array($type, [(new User)->getMorphClass(), User::class], true)) {
                    $primaryParticipantId = $id;
                }
            }
        }

        return new RegistrationSnapshot(
            id: (string) $registration->getKey(),
            eventId: (string) $registration->getAttribute('event_id'),
            eventTitle: $event instanceof Model ? $this->scalar($event->getAttribute('title')) : null,
            eventStartsAt: $event instanceof Model ? $this->date($event->getAttribute('starts_at')) : null,
            eventStatus: $event instanceof Model ? $this->scalar($event->getAttribute('status')) : null,
            createdAt: $this->date($registration->getAttribute('created_at')),
            registeredAt: $this->date($registration->getAttribute('registered_at')),
            registrantType: $this->scalar($registration->getAttribute('registrant_type')),
            registrantId: $this->scalar($registration->getAttribute('registrant_id')),
            status: $registration->statusValue(),
            externalOrderId: $this->scalar($registration->getAttribute('external_order_id')),
            eventOccurrenceId: $this->scalar($registration->getAttribute('event_occurrence_id')),
            eventSessionId: $this->scalar($registration->getAttribute('event_session_id')),
            primaryParticipantUserId: $primaryParticipantId,
            totalAmount: (int) $registration->getAttribute('total_amount'),
            paymentStatus: $this->scalar($registration->getAttribute('payment_status')),
            cancelledAt: $this->date($registration->getAttribute('cancelled_at')),
            fraudStatus: $this->scalar($registration->getAttribute('fraud_status')),
            metadata: $metadata,
            isAmbiguousDevice: in_array($registration->getAttribute('is_ambiguous_device') ?? ($metadata['is_ambiguous_device'] ?? false), [true, 1, '1'], true),
            isBot: in_array($registration->getAttribute('is_bot') ?? ($metadata['is_bot'] ?? false), [true, 1, '1'], true),
        );
    }

    public function checkIn(EventCheckin $checkIn): CheckInSnapshot
    {
        $event = $checkIn->event;
        $event = $event instanceof Model ? $event : null;
        $verifier = $checkIn->getRelationValue('verifiedBy');
        $metadata = $checkIn->getAttribute('metadata');
        $metadata = is_array($metadata) ? $metadata : [];

        return new CheckInSnapshot(
            id: (string) $checkIn->getKey(),
            eventId: (string) $checkIn->getAttribute('event_id'),
            eventTitle: $event instanceof Model ? $this->scalar($event->getAttribute('title')) : null,
            eventStartsAt: $event instanceof Model ? $this->date($event->getAttribute('starts_at')) : null,
            createdAt: $this->date($checkIn->getAttribute('created_at')),
            checkedInAt: $this->date($checkIn->getAttribute('checked_in_at')),
            checkInSource: $this->scalar($checkIn->getAttribute('check_in_source')),
            verifiedByUserId: $this->scalar($checkIn->getAttribute('verified_by_user_id')),
            verifiedByName: $verifier instanceof Model ? $this->scalar($verifier->getAttribute('name')) : null,
            attendeeType: $this->scalar($checkIn->getAttribute('attendee_type')),
            attendeeId: $this->scalar($checkIn->getAttribute('attendee_id')),
            attendanceType: $this->scalar($checkIn->getAttribute('attendance_type')),
            status: $this->scalar($checkIn->getAttribute('status')),
            cancelledAt: $this->date($checkIn->getAttribute('cancelled_at')),
            fraudStatus: $this->scalar($checkIn->getAttribute('fraud_status')),
            metadata: $metadata,
            isAmbiguousDevice: in_array($checkIn->getAttribute('is_ambiguous_device') ?? ($metadata['is_ambiguous_device'] ?? false), [true, 1, '1'], true),
            isBot: in_array($checkIn->getAttribute('is_bot') ?? ($metadata['is_bot'] ?? false), [true, 1, '1'], true),
        );
    }

    private function scalar(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_object($value) && method_exists($value, 'getValue') ? (string) $value->getValue() : null;
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface
            ? $value->format(DATE_ATOM)
            : (is_string($value) ? $value : null);
    }
}
