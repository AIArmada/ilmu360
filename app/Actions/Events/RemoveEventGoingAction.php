<?php

namespace App\Actions\Events;

use App\Models\Event;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class RemoveEventGoingAction
{
    use AsAction;

    public function __construct(
        private SyncEventGoingCountAction $syncEventGoingCount,
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

        return [
            'deleted' => $deleted,
            'going_count' => $this->syncEventGoingCount->handle($event),
        ];
    }
}
