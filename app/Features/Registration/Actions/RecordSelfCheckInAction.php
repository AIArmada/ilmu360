<?php

declare(strict_types=1);

namespace App\Features\Registration\Actions;

use AIArmada\Events\Contracts\EventCheckInService;
use AIArmada\Events\Models\Event as PackageEvent;
use App\Features\Registration\Api\AttendanceCommands;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Registration\CheckInNotifier;
use App\Shared\ShareTracking\ParticipationAttribution;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Owns the application's self-check-in write path.
 *
 * The vendor EventCheckInService remains the canonical external attendance
 * writer (event_attendances and event_attendance_logs); this action is the
 * app-side aggregate entry point that resolves caller identities through morph
 * aliases, keeps the check-in and share-attribution write in one transaction,
 * and notifies only when this call created the attendance row.
 */
final readonly class RecordSelfCheckInAction implements AttendanceCommands
{
    use AsAction;

    public function __construct(
        private EventCheckInService $checkIns,
        private ParticipationAttribution $attribution,
        private CheckInNotifier $notifier,
    ) {}

    /**
     * @return array{status: 'created'|'duplicate', checkin_id: string}
     */
    public function recordSelfCheckIn(
        string $eventId,
        string $userId,
        ?string $registrationId,
        string $method,
        ?object $request = null,
    ): array {
        $eventClass = MorphModelResolver::resolve('event');
        $userClass = MorphModelResolver::resolve('user');
        $checkinClass = MorphModelResolver::resolve('checkin');

        /** @var PackageEvent $event */
        $event = $eventClass::query()->findOrFail($eventId);

        $user = $userClass::query()->findOrFail($userId);

        /** @var array{status: 'created'|'duplicate', checkin_id: string} $resultData */
        $resultData = DB::transaction(function () use ($event, $user, $registrationId, $method, $request, $checkinClass): array {
            $result = $this->checkIns->checkInWithResult([
                'event_id' => $event->getKey(),
                'event_registration_id' => $registrationId,
                'event_occurrence_id' => $event->primaryOccurrence?->getKey(),
                'attendee_type' => $user->getMorphClass(),
                'attendee_id' => $user->getKey(),
                'attendance_type' => 'check_in',
                'check_in_source' => $method,
            ]);

            $checkin = $result->attendance instanceof $checkinClass
                ? $result->attendance
                : $checkinClass::query()->findOrFail($result->attendance->getKey());

            $checkin->refresh();

            /** @var array{status: 'created'|'duplicate', checkin_id: string} $data */
            $data = [
                'status' => $result->created ? 'created' : 'duplicate',
                'checkin_id' => (string) $checkin->getKey(),
            ];

            if ($data['status'] !== 'created') {
                return $data;
            }

            $linkedRegistrationId = $checkin->getAttribute('event_registration_id');

            if (is_string($linkedRegistrationId) && $linkedRegistrationId !== '') {
                $this->attribution->recordInheritedCheckIn(
                    checkinId: (string) $checkin->getKey(),
                    eventId: (string) $event->getKey(),
                    actorId: (string) $user->getKey(),
                    registrationId: $linkedRegistrationId,
                );
            } else {
                $this->attribution->recordCheckIn(
                    checkinId: (string) $checkin->getKey(),
                    eventId: (string) $event->getKey(),
                    actorId: (string) $user->getKey(),
                    request: $request,
                );
            }

            return $data;
        });

        if ($resultData['status'] === 'created') {
            $this->notifier->notifyConfirmed($resultData['checkin_id']);
        }

        return $resultData;
    }
}
