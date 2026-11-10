<?php

namespace App\Livewire\Concerns;

use AIArmada\Engagement\Contracts\EngagementManager;
use AIArmada\Engagement\Models\Bookmark;
use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Enums\ShareOutcomeType;
use App\Models\Event;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Shared\Engagement\EngagementReader;
use App\Support\Auth\IntendedRedirect;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

trait TogglesEventSave
{
    abstract protected function eventSaveReturnUrl(): string;

    public function toggleSave(string $eventId): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirect(
                IntendedRedirect::loginUrl($this->eventSaveReturnUrl()),
                navigate: true,
            );

            return;
        }

        $event = Event::query()
            ->active()
            ->whereKey($eventId)
            ->first();

        if (! $event instanceof Event || ! in_array((string) $event->status, Event::ENGAGEABLE_STATUSES, true)) {
            return;
        }

        $isSaved = Bookmark::forBookmarker($user)
            ->forBookmarkable($event)
            ->active()
            ->exists();

        if ($isSaved) {
            DB::transaction(function () use ($user, $event): void {
                $bookmark = Bookmark::forBookmarker($user)->forBookmarkable($event)->active()->lockForUpdate()->first();

                app(EngagementManager::class)->removeBookmark($user, $event);

                if ($bookmark instanceof Bookmark) {
                    app(ReverseShareOutcomeAction::class)->handle(
                        ShareOutcomeBusinessKey::eventSave((string) $bookmark->getKey()),
                        'save_removed',
                        $user,
                        request(),
                    );
                }
            });

            return;
        }

        DB::transaction(function () use ($user, $event): void {
            $bookmark = app(EngagementManager::class)->bookmark($user, $event);

            if ($bookmark instanceof Bookmark && $bookmark->wasRecentlyCreated) {
                app(ShareTrackingService::class)->recordOutcome(
                    type: ShareOutcomeType::EventSave,
                    businessKey: ShareOutcomeBusinessKey::eventSave((string) $bookmark->getKey()),
                    subject: $event,
                    actor: $user,
                    request: request(),
                    metadata: ['subject_id' => $event->getKey(), 'subject_type' => $event->getMorphClass()],
                    occurredAt: $bookmark->created_at,
                );
            }
        });
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function savedEventIds(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return app(EngagementReader::class)->savedEventIds((string) $user->getKey());
    }
}
