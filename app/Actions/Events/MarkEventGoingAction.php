<?php

namespace App\Actions\Events;

use AIArmada\Engagement\Contracts\EngagementCounterService;
use AIArmada\Events\Actions\RegisterForFreeAction;
use AIArmada\Ticketing\Enums\PricingMode;
use App\Enums\DawahShareOutcomeType;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class MarkEventGoingAction
{
    use AsAction;

    public function __construct(
        private EngagementCounterService $engagementCounter,
        private ShareTrackingService $shareTrackingService,
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
                $created = app(RegisterForFreeAction::class)->execute(
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

                // Going-triggered admissions are canonical registrations and
                // persist provenance in the caller's transaction like every
                // other free/admission path. Only rows created by this call
                // freeze a decision; idempotent replays never late-credit.
                foreach ($created as $admission) {
                    if (! $admission instanceof Model || ! $admission->wasRecentlyCreated) {
                        continue;
                    }

                    $admission->refresh();

                    $createdAt = $admission->created_at;

                    $this->shareTrackingService->recordOutcome(
                        type: DawahShareOutcomeType::EventRegistration,
                        businessKey: ShareOutcomeBusinessKey::eventRegistration((string) $admission->getKey()),
                        subject: $event,
                        actor: $user,
                        request: $request ?? request(),
                        metadata: [
                            'registration_id' => (string) $admission->getKey(),
                            'guest' => false,
                            'via_going' => true,
                        ],
                        occurredAt: $createdAt instanceof CarbonInterface ? $createdAt : now(),
                    );
                }
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
