<?php

namespace App\Actions\Events;

use AIArmada\Events\Actions\IssueEventRegistrationPassesAction;
use AIArmada\Events\Contracts\RegistrationServiceInterface;
use AIArmada\Events\Enums\PricingMode;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class MarkEventGoingAction
{
    use AsAction;

    public function handle(Event $event, User $user, Request $request): array
    {
        $user->respond($event, 'going');

        if ($event->pricing_mode === PricingMode::Free?->value || $event->pricing_mode === null) {
            if (config('events.features.auto_issue_passes', true)) {
                $regData = app(RegistrationServiceInterface::class)->register([
                    'event_id' => $event->getKey(),
                    'user_id' => $user->getKey(),
                ]);
                $registration = Registration::findOrFail($regData->id);
                app(IssueEventRegistrationPassesAction::class)->handle($registration);
            }
        }

        return [
            'status' => 'going',
            'going_count' => $event->goingBy()->active()->count(),
        ];
    }
}
