<?php

namespace App\Observers;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Actions\Events\GenerateEventSlugAction;
use App\Actions\Slugs\SyncSlugRedirectAction;
use App\Actions\Speakers\GenerateSpeakerSlugAction;
use App\Models\Person;
use App\Observers\Concerns\SyncsCurrentAndPreviousValues;
use App\Support\Cache\PublicDirectoryCacheVersion;
use App\Support\Cache\PublicListingsCache;
use App\Support\Search\SpeakerSearchService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class PersonObserver implements ShouldHandleEventsAfterCommit
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

    public function saved(Person $person): void
    {
        if (! $person->wasRecentlyCreated && ! $person->wasChanged()) {
            return;
        }

        $searchableNameChanged = $person->wasRecentlyCreated || $person->wasChanged([
            'name',
        ]);

        if ($searchableNameChanged) {
            $this->speakerSearchService->syncSpeakerRecord($person);

            OwnerContext::withOwner(null, function () use ($person): void {
                $this->syncCurrentAndPreviousString(
                    $person->name,
                    $person->wasChanged('name') ? ($person->getPrevious()['name'] ?? null) : null,
                    fn (string $name): bool => $this->generateSpeakerSlugAction->syncSpeakerSlugsForName($name),
                    fn (string $name): bool => $this->generateEventSlugAction->syncEventSlugsForSpeakerName($name),
                );
            });
        }

        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->publicDirectoryCacheVersion->bumpSpeaker();
    }

    public function deleted(Person $person): void
    {
        $this->syncSlugRedirectAction->purgeForModel($person);
        $this->generateSpeakerSlugAction->syncSpeakerSlugsForName($person->name);
        $this->generateEventSlugAction->syncEventSlugsForSpeakerId((string) $person->getKey());
        $this->generateEventSlugAction->syncEventSlugsForSpeakerName($person->name);
        $this->speakerSearchService->purgeSpeakerRecord($person);
        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->publicDirectoryCacheVersion->bumpSpeaker();
    }
}
