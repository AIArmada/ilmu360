<?php

namespace App\Actions\Events;

use AIArmada\Engagement\Contracts\EngagementCounterService;
use App\Models\Event;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class RemoveEventGoingAction
{
    use AsAction;

    public function __construct(
        private EngagementCounterService $engagementCounter,
    ) {}

    /**
     * @return array{deleted: bool, going_count: int}
     */
    public function handle(string $eventId, User $user): array
    {
        $event = Event::query()->find($eventId);

        if (! $event instanceof Event) {
            return [
                'deleted' => false,
                'going_count' => 0,
            ];
        }

        $deleted = $event->goingBy()
            ->forResponder($user)
            ->active()
            ->exists();

        if ($deleted) {
            $user->cancelResponse($event);
        }

        $this->engagementCounter->recalculateResponses($event, 'going');

        return [
            'deleted' => $deleted,
            'going_count' => $this->engagementCounter->value($event, 'responses', 'going'),
        ];
    }
}
