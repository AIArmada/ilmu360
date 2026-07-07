<?php

namespace App\Actions\Events;

use App\Models\Event;
use App\Models\User;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class MarkEventGoingAction
{
    use AsAction;

    public function handle(Event $event, User $user, Request $request): array
    {
        $user->respond($event, 'going');

        return [
            'status' => 'going',
            'going_count' => $event->goingBy()->active()->count(),
        ];
    }
}
