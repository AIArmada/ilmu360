<?php

namespace App\Observers;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Actions\Events\GenerateEventSlugAction;
use App\Actions\Slugs\SyncCanonicalSlugAction;
use App\Actions\Slugs\SyncSlugRedirectAction;
use App\Models\Event;
use App\Observers\Concerns\SyncsCurrentAndPreviousValues;
use App\Support\Cache\PublicDirectoryCacheVersion;
use App\Support\Cache\PublicListingsCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class EventObserver implements ShouldHandleEventsAfterCommit
{
    use SyncsCurrentAndPreviousValues;

    public function __construct(
        protected GenerateEventSlugAction $generateEventSlugAction,
        protected SyncCanonicalSlugAction $syncCanonicalSlugAction,
        protected SyncSlugRedirectAction $syncSlugRedirectAction,
        protected PublicDirectoryCacheVersion $publicDirectoryCacheVersion,
        protected PublicListingsCache $publicListingsCache
    ) {}

    public function creating(Event $event): void
    {
        if (blank($event->slug)) {
            $event->slug = $this->generateEventSlugAction->handle(
                $event->title,
                $event->starts_at,
                is_string($event->timezone) ? $event->timezone : null,
                (string) $event->getKey(),
                [],
            );
        }
    }

    /**
     * Handle the Event "updating" event.
     */
    public function updating(Event $event): void {}

    public function created(Event $event): void
    {
        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->publicDirectoryCacheVersion->bumpForEvent($event);
    }

    public function updated(Event $event): void
    {
        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->publicDirectoryCacheVersion->bumpForEvent($event);

        if ($event->wasChanged('slug')) {
            /**
             * Direct event write paths can persist the canonical slug before the
             * observer runs, so redirects are synchronized here from the model's
             * previous persisted slug snapshot.
             */
            $this->syncCanonicalSlugAction->syncChanged(
                $event,
                $event->getPrevious()['slug'] ?? null,
            );
        }

        $needsSlugSync = $event->wasChanged(['title', 'timezone']);

        if ($needsSlugSync) {
            OwnerContext::withOwner(null, function () use ($event): void {
                $this->syncCurrentAndPreviousString(
                    $event->title,
                    $event->getPrevious()['title'] ?? null,
                    fn (string $title): bool => $this->generateEventSlugAction->syncEventSlugsForTitle($title),
                );
            });
        }

    }

    /**
     * Scout's model observer covers the app-owned fields. These package-backed
     * fields are intentionally synced here when their canonical event columns change.
     */
    public function saved(Event $event): void
    {
        if ($event->wasRecentlyCreated || ! $event->wasChanged([
            'metadata',
            'default_venue_id',
            'delivery_mode',
            'timezone',
        ])) {
            return;
        }

        if (! $event->shouldBeSearchable()) {
            if ($event->wasSearchableBeforeUpdate()) {
                $event->unsearchable();
            }

            return;
        }

        $event->searchable();
    }

    public function deleted(Event $event): void
    {
        $this->syncSlugRedirectAction->purgeForModel($event);
        $this->generateEventSlugAction->syncEventSlugsForTitle($event->title);
        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->publicDirectoryCacheVersion->bumpForEvent($event);
    }
}
