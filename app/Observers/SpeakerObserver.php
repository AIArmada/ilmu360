<?php

namespace App\Observers;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Actions\Events\GenerateEventSlugAction;
use App\Actions\Slugs\SyncSlugRedirectAction;
use App\Actions\Speakers\GenerateSpeakerSlugAction;
use App\Models\Speaker;
use App\Observers\Concerns\SyncsCurrentAndPreviousValues;
use App\Support\Cache\PublicDirectoryCacheVersion;
use App\Support\Cache\PublicListingsCache;
use App\Support\Search\SpeakerSearchService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class SpeakerObserver implements ShouldHandleEventsAfterCommit
{
    use SyncsCurrentAndPreviousValues;

    public function __construct(
        protected GenerateEventSlugAction $generateEventSlugAction,
        protected GenerateSpeakerSlugAction $generateSpeakerSlugAction,
        protected SyncSlugRedirectAction $syncSlugRedirectAction,
        protected PublicDirectoryCacheVersion $publicDirectoryCacheVersion,
        protected PublicListingsCache $publicListingsCache,
        protected SpeakerSearchService $speakerSearchService,
    ) {}

    public function saved(Speaker $speaker): void
    {
        if (! $speaker->wasRecentlyCreated && ! $speaker->wasChanged()) {
            return;
        }

        $searchableNameChanged = $speaker->wasRecentlyCreated || $speaker->wasChanged([
            'name',
            'honorific',
            'pre_nominal',
            'post_nominal',
        ]);

        if ($searchableNameChanged) {
            $this->speakerSearchService->syncSpeakerRecord($speaker);

            OwnerContext::withOwner(null, function () use ($speaker): void {
                $this->syncCurrentAndPreviousString(
                    $speaker->name,
                    $speaker->wasChanged('name') ? ($speaker->getPrevious()['name'] ?? null) : null,
                    fn (string $name): bool => $this->generateSpeakerSlugAction->syncSpeakerSlugsForName($name),
                    fn (string $name): bool => $this->generateEventSlugAction->syncEventSlugsForSpeakerName($name),
                );
            });
        }

        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->publicDirectoryCacheVersion->bumpSpeaker();
    }

    public function deleted(Speaker $speaker): void
    {
        $this->syncSlugRedirectAction->purgeForModel($speaker);
        $this->generateSpeakerSlugAction->syncSpeakerSlugsForName($speaker->name);
        $this->generateEventSlugAction->syncEventSlugsForSpeakerId((string) $speaker->getKey());
        $this->generateEventSlugAction->syncEventSlugsForSpeakerName($speaker->name);
        $this->speakerSearchService->purgeSpeakerRecord($speaker);
        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->publicDirectoryCacheVersion->bumpSpeaker();
    }
}
