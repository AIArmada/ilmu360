<?php

namespace App\Actions\Events\Engagement;

use AIArmada\Engagement\Contracts\EngagementCounterService;
use AIArmada\Ticketing\Enums\PricingMode;
use App\Features\Registration\Api\AdmissionRegistration;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class MarkEventGoingAction
{
    use AsAction;

    public function __construct(
        private EngagementCounterService $engagementCounter,
        private AdmissionRegistration $admissions,
    ) {}

    /**
     * @return array{status: 'created'|'existing', going_count: int, response_created: bool}
     */
    public function handle(Event $event, User $user, ?Request $request = null): array
    {
        $alreadyGoing = $event->goingBy()
            ->forResponder($user)
            ->active()
            ->exists();

        $response = $user->respond($event, 'going');

        // Reactivations reuse the response row: only rows created by this
        // call freeze an outcome decision. The public status keeps its
        // action-level meaning (the user newly expressed going) while
        // response_created carries the row-level truth for provenance.
        $responseCreated = $response->wasRecentlyCreated;

        $pricingMode = $event->pricing_mode instanceof PricingMode
            ? $event->pricing_mode->value
            : $event->pricing_mode;

        if (($pricingMode === PricingMode::Free->value || $pricingMode === null) && config('events.features.auto_issue_passes', true)) {
            $registration = Registration::query()
                ->forUser($user)
                ->where('event_id', $event->getKey())
                ->active()
                ->first();
            if (! $registration instanceof Registration) {
                // Going-triggered admissions are canonical registrations and
                // persist provenance in the caller's transaction like every
                // other free/admission path. The admission boundary records
                // only rows created by this call, so idempotent replays never
                // late-credit through a new cookie.
                $this->admissions->registerFree(
                    eventId: (string) $event->getKey(),
                    participants: [[
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'is_primary' => true,
                        'is_purchaser' => true,
                    ]],
                    registrantId: (string) $user->getKey(),
                    options: [
                        'with_pass' => true,
                        'attribution_metadata' => ['via_going' => true],
                    ],
                    request: $request,
                );
            }
        }

        $this->engagementCounter->recalculateResponses($event, 'going');

        return [
            'status' => $alreadyGoing ? 'existing' : 'created',
            'going_count' => $this->engagementCounter->value($event, 'responses', 'going'),
            'response_created' => $responseCreated,
        ];
    }
}
