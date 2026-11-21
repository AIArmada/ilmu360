<?php

namespace App\Features\Events\Actions\Lifecycle;

use AIArmada\CommerceSupport\Contracts\SlugRedirectRecorder;
use AIArmada\CommerceSupport\Support\CanonicalSlug;
use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Discovery\Api\PublicListingsCacheInvalidator;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\ShareCardWarmScheduler;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Api\InstitutionListingCacheVersion;
use App\Features\Persons\Api\PersonListingCacheVersion;
use App\Features\SlugRedirects\Api\SlugRedirectCommands;
use App\Platform\Models\Concerns\SyncsCurrentAndPreviousValues;

final readonly class ApplyEventLifecycleEffectsAction
{
    use SyncsCurrentAndPreviousValues;

    public function __construct(
        protected EventAuthoring $eventAuthoring,
        protected SlugRedirectRecorder $slugRedirectRecorder,
        protected SlugRedirectCommands $slugRedirectCommands,
        protected InstitutionListingCacheVersion $institutionCacheVersion,
        protected PersonListingCacheVersion $personCacheVersion,
        protected PublicListingsCacheInvalidator $publicListingsCache,
        private ShareCardWarmScheduler $shareCards,
    ) {}

    public function creating(Event $event): void
    {
        if (blank($event->slug)) {
            $event->slug = $this->eventAuthoring->eventSlug(
                $event->title,
                $event->starts_at,
                is_string($event->timezone) ? $event->timezone : null,
                (string) $event->getKey(),
                [],
            );
        }
    }

    public function created(Event $event): void
    {
        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->institutionCacheVersion->bump();
        $this->personCacheVersion->bump();

        if ($event->isPubliclyReachable()) {
            $this->shareCards->prewarmForEvent($event->getKey());
        }
    }

    public function updated(Event $event): void
    {
        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->institutionCacheVersion->bump();
        $this->personCacheVersion->bump();

        if ($event->wasChanged('slug')) {
            CanonicalSlug::syncChanged(
                $event,
                $event->getPrevious()['slug'] ?? null,
                $this->slugRedirectRecorder,
            );
        }

        if ($event->isPubliclyReachable() && $event->wasChanged([
            'title',
            'status',
            'published_at',
            'cancelled_at',
            'visibility',
            'timezone',
            'starts_at',
            'ends_at',
            'prayer_reference',
            'prayer_offset',
            'prayer_display_text',
            'institution_id',
            'default_venue_id',
        ])) {
            $this->shareCards->prewarmForEvent($event->getKey());
        }

        $needsSlugSync = $event->wasChanged(['title', 'timezone']);

        if ($needsSlugSync) {
            OwnerContext::withOwner(null, function () use ($event): void {
                $this->syncCurrentAndPreviousString(
                    $event->title,
                    $event->getPrevious()['title'] ?? null,
                    fn (string $title): bool => $this->eventAuthoring->syncEventSlugsForTitle($title),
                );
            });
        }

    }

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
        $this->slugRedirectCommands->purge($event->getMorphClass(), (string) $event->getKey());
        $this->eventAuthoring->syncEventSlugsForTitle($event->title);
        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->institutionCacheVersion->bump();
        $this->personCacheVersion->bump();
    }
}
