<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

use AIArmada\Engagement\Contracts\EngagementManager;
use AIArmada\Engagement\Models\Bookmark;
use App\Models\Event;
use App\Models\User;
use App\Shared\Engagement\EngagementShareOutcomes;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Root remove-save writer for the package-canonical bookmark rows.
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

    public function handle(Event $event, User $user): UnsaveEventResult
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
