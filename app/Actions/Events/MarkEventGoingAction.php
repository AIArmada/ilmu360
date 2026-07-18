<?php

namespace App\Actions\Events;

use AIArmada\Engagement\Contracts\EngagementCounterService;
use AIArmada\Events\Actions\RegisterForFreeAction;
use AIArmada\Events\Enums\PricingMode;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class MarkEventGoingAction
{
    use AsAction;

    public function __construct(
        private EngagementCounterService $engagementCounter,
    ) {}

    /**
     * @return array{status: 'created'|'existing', going_count: int}
     */
    public function handle(Event $event, User $user): array
    {
        $alreadyGoing = $event->goingBy()
            ->forResponder($user)
            ->active()
            ->exists();

        $user->respond($event, 'going');

        if (($event->pricing_mode === PricingMode::Free->value || $event->pricing_mode === null) && config('events.features.auto_issue_passes', true)) {
            $registration = Registration::query()
                ->forUser($user)
                ->where('event_id', $event->getKey())
                ->active()
                ->first();
            if (! $registration instanceof Registration) {
                app(RegisterForFreeAction::class)->execute(
                    target: $event,
                    participants: [[
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'is_primary' => true,
                        'is_purchaser' => true,
                    ]],
                    registrant: $user,
                    options: ['with_pass' => true],
                );
            }
        }

        $this->engagementCounter->recalculateResponses($event, 'going');

        return [
            'status' => $alreadyGoing ? 'existing' : 'created',
            'going_count' => $this->engagementCounter->value($event, 'responses', 'going'),
        ];
    }
}
