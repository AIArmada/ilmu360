<?php

namespace App\Actions\Events;

use AIArmada\Events\Contracts\EventCheckInService;
use App\Enums\DawahShareOutcomeType;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\User;
use App\Services\Notifications\EventNotificationService;
use App\Services\ShareTrackingService;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class RecordEventCheckInAction
{
    use AsAction;

    public function __construct(
        private EventCheckInService $checkIns,
        private ShareTrackingService $shareTrackingService,
        private EventNotificationService $eventNotificationService,
    ) {}

    /**
     * @return array{status: 'created'|'duplicate', checkin: EventCheckin}
     */
    public function handle(
        Event $event,
        User $user,
        ?string $registrationId,
        string $method,
        ?Request $request = null,
    ): array {
        $result = $this->checkIns->checkInWithResult([
            'event_id' => $event->getKey(),
            'event_registration_id' => $registrationId,
            'event_occurrence_id' => $event->primaryOccurrence?->getKey(),
            'attendee_type' => $user->getMorphClass(),
            'attendee_id' => $user->getKey(),
            'attendance_type' => 'check_in',
            'check_in_source' => $method,
        ]);

        $checkin = $result->attendance instanceof EventCheckin
            ? $result->attendance
            : EventCheckin::query()->findOrFail($result->attendance->getKey());

        /** @var array{status: 'created'|'duplicate', checkin: EventCheckin} $resultData */
        $resultData = [
            'status' => $result->created ? 'created' : 'duplicate',
            'checkin' => $checkin,
        ];

        if ($resultData['status'] === 'created') {
            $this->shareTrackingService->recordOutcome(
                type: DawahShareOutcomeType::EventCheckin,
                outcomeKey: 'event_checkin:checkin:'.$resultData['checkin']->id,
                subject: $event,
                actor: $user,
                request: $request ?? request(),
                metadata: [
                    'checkin_id' => $resultData['checkin']->id,
                    'event_registration_id' => $resultData['checkin']->event_registration_id,
                    'check_in_source' => $resultData['checkin']->check_in_source,
                ],
            );

            $this->eventNotificationService->notifyCheckinConfirmed($resultData['checkin']);
        }

        return $resultData;
    }
}
