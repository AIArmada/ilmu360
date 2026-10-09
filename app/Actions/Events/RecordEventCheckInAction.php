<?php

namespace App\Actions\Events;

use AIArmada\Events\Contracts\EventCheckInService;
use App\Enums\DawahShareOutcomeType;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\User;
use App\Services\Notifications\EventNotificationService;
use App\Services\ShareTrackingService;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        /** @var array{status: 'created'|'duplicate', checkin: EventCheckin} $resultData */
        $resultData = DB::transaction(function () use ($event, $user, $registrationId, $method, $request): array {
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

            $checkin->refresh();

            /** @var array{status: 'created'|'duplicate', checkin: EventCheckin} $data */
            $data = [
                'status' => $result->created ? 'created' : 'duplicate',
                'checkin' => $checkin,
            ];

            if ($data['status'] !== 'created') {
                return $data;
            }

            $businessKey = ShareOutcomeBusinessKey::eventCheckin((string) $checkin->getKey());
            $linkedRegistrationId = $checkin->event_registration_id;
            $occurredAt = $checkin->created_at ?? now();

            if (is_string($linkedRegistrationId) && $linkedRegistrationId !== '') {
                $this->shareTrackingService->recordInheritedOutcome(
                    type: DawahShareOutcomeType::EventCheckin,
                    businessKey: $businessKey,
                    sourceBusinessKey: ShareOutcomeBusinessKey::eventRegistration($linkedRegistrationId),
                    subject: $event,
                    actor: $user,
                    metadata: [
                        'checkin_id' => $checkin->getKey(),
                        'event_registration_id' => $linkedRegistrationId,
                        'check_in_source' => $checkin->check_in_source,
                    ],
                    occurredAt: $occurredAt,
                );
            } else {
                $this->shareTrackingService->recordOutcome(
                    type: DawahShareOutcomeType::EventCheckin,
                    businessKey: $businessKey,
                    subject: $event,
                    actor: $user,
                    request: $request ?? request(),
                    metadata: [
                        'checkin_id' => $checkin->getKey(),
                        'event_registration_id' => $checkin->event_registration_id,
                        'check_in_source' => $checkin->check_in_source,
                    ],
                    occurredAt: $occurredAt,
                );
            }

            return $data;
        });

        if ($resultData['status'] === 'created') {
            $this->eventNotificationService->notifyCheckinConfirmed($resultData['checkin']);
        }

        return $resultData;
    }
}
