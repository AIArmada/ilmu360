<?php

declare(strict_types=1);

namespace App\Features\Engagement\Actions;

use AIArmada\Engagement\Contracts\Bookmarkable;
use AIArmada\Engagement\Contracts\CanInteract;
use AIArmada\Engagement\Contracts\EngagementManager;
use AIArmada\Engagement\Models\Bookmark;
use App\Features\Engagement\Api\EngagementShareOutcomes;
use App\Shared\Auth\AccountActor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Engagement-owned remove-save writer for the package-canonical bookmark rows.
 *
 * Locks the caller's active bookmark inside the same transaction that removes
 * it, then reverses the event-save share outcome through the
 * EngagementShareOutcomes contract only when a row was actually present.
 */
final readonly class UnsaveEventAction
{
    use AsAction;

    public function __construct(
        private EngagementManager $engagement,
        private EngagementShareOutcomes $shareOutcomes,
    ) {}

    public function handle(Model&Bookmarkable $event, Model&CanInteract&Authenticatable&AccountActor $user): UnsaveEventResult
    {
        return DB::transaction(function () use ($event, $user): UnsaveEventResult {
            $bookmark = Bookmark::forBookmarker($user)
                ->forBookmarkable($event)
                ->active()
                ->lockForUpdate()
                ->first();

            $removed = $bookmark instanceof Bookmark;

            $this->engagement->removeBookmark($user, $event);

            if ($removed && $bookmark instanceof Bookmark) {
                $this->shareOutcomes->reverseBookmark(
                    bookmarkId: (string) $bookmark->getKey(),
                    reason: 'save_removed',
                    actor: $user,
                );
            }

            return new UnsaveEventResult(removed: $removed);
        });
    }
}
