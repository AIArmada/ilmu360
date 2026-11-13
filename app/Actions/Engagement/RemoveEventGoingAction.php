<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

use AIArmada\Engagement\Contracts\EngagementCounterService;
use AIArmada\Engagement\Models\Response;
use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Models\Event;
use App\Models\User;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Root remove-going writer for the package-canonical response rows.
 *
 * Locks the caller's active response inside the same transaction that cancels
 * it, recalculates the counter and reverses the event-going share outcome only
 * when a row was actually present. Idempotent replays never reverse again.
 * D4e will switch the reversal to the EngagementShareOutcomes contract.
 */
final readonly class RemoveEventGoingAction
{
    use AsAction;

    public function __construct(
        private EngagementCounterService $engagementCounter,
        private ReverseShareOutcomeAction $reverseShareOutcome,
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
                $this->reverseShareOutcome->handle(
                    ShareOutcomeBusinessKey::eventGoing((string) $response->getKey()),
                    'going_removed',
                    $user,
                );
            }

            return [
                'deleted' => $deleted,
                'going_count' => $this->engagementCounter->value($event, 'responses', 'going'),
            ];
        });
    }
}
