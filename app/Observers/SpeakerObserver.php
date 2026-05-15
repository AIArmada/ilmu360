<?php

namespace App\Observers;

use App\Actions\Events\GenerateEventSlugAction;
use App\Actions\Slugs\SyncSlugRedirectAction;
use App\Actions\Speakers\GenerateSpeakerSlugAction;
use App\Models\Speaker;
use App\Observers\Concerns\SyncsCurrentAndPreviousValues;
use App\Support\Cache\PublicDirectoryCacheVersion;
use App\Support\Cache\PublicListingsCache;
use App\Support\Search\SpeakerSearchService;

class SpeakerObserver
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
        $this->speakerSearchService->syncSpeakerRecord($speaker);

        if ($speaker->wasRecentlyCreated || $speaker->wasChanged(['name', 'honorific', 'pre_nominal', 'post_nominal'])) {
            $this->syncCurrentAndPreviousString(
                $speaker->name,
                $speaker->wasChanged('name') ? ($speaker->getPrevious()['name'] ?? null) : null,
                fn (string $name): bool => $this->generateSpeakerSlugAction->syncSpeakerSlugsForName($name),
                fn (string $name): bool => $this->generateEventSlugAction->syncEventSlugsForSpeakerName($name),
            );
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
