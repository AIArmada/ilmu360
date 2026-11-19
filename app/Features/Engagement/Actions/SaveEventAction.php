<?php

declare(strict_types=1);

namespace App\Features\Engagement\Actions;

use AIArmada\Engagement\Contracts\Bookmarkable;
use AIArmada\Engagement\Contracts\CanInteract;
use AIArmada\Engagement\Contracts\EngagementManager;
use App\Features\Engagement\Api\EngagementShareOutcomes;
use App\Features\ShareTracking\Api\Data\ShareSubject;
use App\Shared\Auth\AccountActor;
use App\Shared\ShareTracking\ShareRequestContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Engagement-owned save writer for the package-canonical bookmark rows.
 *
 * The package owns bookmark storage; the Engagement feature owns the manager write and
 * records the event-save share outcome through the EngagementShareOutcomes
 * contract; no caller should reproduce either.
 */
final readonly class SaveEventAction
{
    use AsAction;

    public function __construct(
        private EngagementManager $engagement,
        private EngagementShareOutcomes $shareOutcomes,
    ) {}

    public function handle(Model&Bookmarkable $event, Model&CanInteract&Authenticatable&AccountActor $user, ?Request $request = null): SaveEventResult
    {
        return DB::transaction(function () use ($event, $user, $request): SaveEventResult {
            $bookmark = $this->engagement->bookmark($user, $event);
            $created = $bookmark->wasRecentlyCreated;

            if ($created) {
                $this->shareOutcomes->recordBookmark(
                    bookmarkId: (string) $bookmark->getKey(),
                    subject: ShareSubject::for('event', (string) $event->getKey(), (string) data_get($event, 'title')),
                    actor: $user,
                    request: $request instanceof Request ? ShareRequestContext::fromRequest($request) : null,
                    metadata: ['subject_id' => $event->getKey(), 'subject_type' => $event->getMorphClass()],
                    occurredAt: $bookmark->created_at,
                );
            }

            return new SaveEventResult(bookmark: $bookmark, created: $created);
        });
    }
}
