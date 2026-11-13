<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

use AIArmada\Engagement\Contracts\EngagementCounterService;
use AIArmada\Engagement\Models\Response;
use App\Models\Event;
use App\Models\User;
use App\Shared\Engagement\EngagementShareOutcomes;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Root remove-going writer for the package-canonical response rows.
 *
 * Locks the caller's active response inside the same transaction that cancels
 * it, recalculates the counter and reverses the event-going share outcome
 * through the EngagementShareOutcomes contract only when a row was actually
 * present. Idempotent replays never reverse again.
 */
final readonly class RemoveEventGoingAction
{
    use AsAction;

    public function __construct(
        private EngagementCounterService $engagementCounter,
        private EngagementShareOutcomes $shareOutcomes,
    ) {}

    /**
     * @return array{deleted: bool, going_count: int}
     */
    public function handle(Event $event, User $user): array
    {
        return DB::transaction(function () use ($event, $user): array {
            $response = Response::query()
                ->where('respondable_type', $event->getMorphClass())
                ->where('respondable_id', $event->getKey())
                ->where('responder_type', $user->getMorphClass())
                ->where('responder_id', $user->getKey())
                ->where('response_type', 'going')
                ->active()
                ->lockForUpdate()
                ->first();

            $deleted = $response instanceof Response;

            if ($deleted) {
                $user->cancelResponse($event);
            }

            $this->engagementCounter->recalculateResponses($event, 'going');

            if ($deleted && $response instanceof Response) {
                $this->shareOutcomes->reverseResponse(
                    responseId: (string) $response->getKey(),
                    reason: 'going_removed',
                    actor: $user,
                );
            }

            return [
                'deleted' => $deleted,
                'going_count' => $this->engagementCounter->value($event, 'responses', 'going'),
            ];
        });
    }
}
