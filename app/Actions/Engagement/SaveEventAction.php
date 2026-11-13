<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

use AIArmada\Engagement\Contracts\EngagementManager;
use App\Enums\ShareOutcomeType;
use App\Models\Event;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Root save writer for the package-canonical bookmark rows.
 *
 * The package engagement tables have no application owner, so this named
 * action stays in the root Actions namespace. It owns the manager write and
 * the existing share-outcome record call; D4e will switch that call to the
 * EngagementShareOutcomes contract, and no caller should reproduce it.
 */
final readonly class SaveEventAction
{
    use AsAction;

    public function __construct(
        private EngagementManager $engagement,
        private ShareTrackingService $shareTracking,
    ) {}

    public function handle(Event $event, User $user, ?Request $request = null): SaveEventResult
    {
        return DB::transaction(function () use ($event, $user, $request): SaveEventResult {
            $bookmark = $this->engagement->bookmark($user, $event);
            $created = $bookmark->wasRecentlyCreated;

            if ($created) {
                $this->shareTracking->recordOutcome(
                    type: ShareOutcomeType::EventSave,
                    businessKey: ShareOutcomeBusinessKey::eventSave((string) $bookmark->getKey()),
                    subject: $event,
                    actor: $user,
                    request: $request,
                    metadata: ['subject_id' => $event->getKey(), 'subject_type' => $event->getMorphClass()],
                    occurredAt: $bookmark->created_at,
                );
            }

            return new SaveEventResult(bookmark: $bookmark, created: $created);
        });
    }
}
