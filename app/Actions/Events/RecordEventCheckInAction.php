<?php

namespace App\Actions\Events;

use App\Enums\DawahShareOutcomeType;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\User;
use App\Services\Notifications\EventNotificationService;
use App\Services\ShareTrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class RecordEventCheckInAction
{
    use AsAction;

    public function __construct(
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
        /** @var array{status: 'created'|'duplicate', checkin: EventCheckin} $result */
        $result = DB::transaction(function () use ($event, $user, $registrationId, $method): array {
            Event::query()
                ->whereKey($event->getKey())
                ->lockForUpdate()
                ->first();

            $existingCheckin = EventCheckin::query()
                ->where('event_id', $event->getKey())
                ->where('user_id', $user->getKey())
                ->latest('checked_in_at')
                ->first();

            if ($existingCheckin instanceof EventCheckin) {
                return [
                    'status' => 'duplicate',
                    'checkin' => $existingCheckin,
                ];
            }

            $checkin = EventCheckin::query()->create([
                'event_id' => $event->getKey(),
                'registration_id' => $registrationId,
                'user_id' => $user->getKey(),
                'method' => $method,
                'checked_in_at' => now(),
            ]);

            return [
                'status' => 'created',
                'checkin' => $checkin,
            ];
        }, 3);

        if ($result['status'] === 'created') {
            $this->shareTrackingService->recordOutcome(
                type: DawahShareOutcomeType::EventCheckin,
                outcomeKey: 'event_checkin:checkin:'.$result['checkin']->id,
                subject: $event,
                actor: $user,
                request: $request ?? request(),
                metadata: [
                    'checkin_id' => $result['checkin']->id,
                    'registration_id' => $result['checkin']->registration_id,
                    'method' => $result['checkin']->method,
                ],
            );

            $this->eventNotificationService->notifyCheckinConfirmed($result['checkin']);
        }

        return $result;
    }
}
