<?php

declare(strict_types=1);

namespace App\Features\Engagement\Actions;

use AIArmada\Engagement\Contracts\EngagementCounterService;
use AIArmada\Ticketing\Enums\PricingMode;
use App\Features\Registration\Api\AdmissionRegistration;
use AIArmada\Engagement\Contracts\CanInteract;
use AIArmada\Engagement\Contracts\EngagementManager;
use AIArmada\Engagement\Contracts\Respondable;
use AIArmada\Engagement\Models\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use App\Shared\Engagement\EngagementShareOutcomes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Engagement-owned going writer for package-canonical response rows.
 *
 * The package owns response storage; this feature owns the application orchestration. It owns the response write,
 * going-triggered free admission, counter recalculation and records the
 * event-going share outcome through the EngagementShareOutcomes contract; no
 * caller should reproduce that orchestration.
 */
final readonly class MarkEventGoingAction
{
    use AsAction;

    public function __construct(
        private EngagementManager $engagement,
        private EngagementCounterService $engagementCounter,
        private AdmissionRegistration $admissions,
        private EngagementShareOutcomes $shareOutcomes,
    ) {}

    /**
     * @return array{status: 'created'|'existing', going_count: int, response_created: bool}
     */
    public function handle(Model&Respondable $event, Model&CanInteract&Authenticatable $user, ?Request $request = null): array
    {
        return DB::transaction(function () use ($event, $user, $request): array {
            $alreadyGoing = Response::query()
                ->where('respondable_type', $event->getMorphClass())
                ->where('respondable_id', $event->getKey())
                ->forResponder($user)
                ->responseType('going')
                ->active()
                ->exists();

            $response = $this->engagement->respond($user, $event, 'going');

            // Reactivations reuse the response row: only rows created by this
            // call freeze an outcome decision. The public status keeps its
            // action-level meaning (the user newly expressed going) while
            // response_created carries the row-level truth for provenance.
            $responseCreated = $response->wasRecentlyCreated;

            $pricingMode = $event->getAttribute('pricing_mode') instanceof PricingMode
                ? $event->getAttribute('pricing_mode')->value
                : $event->getAttribute('pricing_mode');

            if (($pricingMode === PricingMode::Free->value || $pricingMode === null) && config('events.features.auto_issue_passes', true)) {
                if (! $this->admissions->hasActiveForUser((string) $event->getKey(), (string) $user->getKey())) {
                    // Going-triggered admissions are canonical registrations and
                    // persist provenance in the caller's transaction like every
                    // other free/admission path. The admission boundary records
                    // only rows created by this call, so idempotent replays never
                    // late-credit through a new cookie.
                    $this->admissions->registerFree(
                        eventId: (string) $event->getKey(),
                        participants: [[
                            'name' => $user->getAttribute('name'),
                            'email' => $user->getAttribute('email'),
                            'phone' => $user->getAttribute('phone'),
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

            if ($responseCreated) {
                $this->shareOutcomes->recordResponse(
                    responseId: (string) $response->getKey(),
                    subject: $event,
                    actor: $user,
                    request: $request,
                    metadata: ['subject_id' => $event->getKey(), 'subject_type' => $event->getMorphClass()],
                    occurredAt: $response->created_at,
                );
            }

            return [
                'status' => $alreadyGoing ? 'existing' : 'created',
                'going_count' => $this->engagementCounter->value($event, 'responses', 'going'),
                'response_created' => $responseCreated,
            ];
        });
    }
}
