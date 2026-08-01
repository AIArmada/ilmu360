<?php

namespace App\Observers;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Actions\Events\GenerateEventSlugAction;
use App\Actions\Persons\GeneratePersonSlugAction;
use App\Actions\Slugs\SyncSlugRedirectAction;
use App\Models\Person;
use App\Observers\Concerns\SyncsCurrentAndPreviousValues;
use App\Support\Cache\PublicDirectoryCacheVersion;
use App\Support\Cache\PublicListingsCache;
use App\Support\Search\PersonSearchService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class PersonObserver implements ShouldHandleEventsAfterCommit
{
    use SyncsCurrentAndPreviousValues;

    public function __construct(
        protected GenerateEventSlugAction $generateEventSlugAction,
        protected GeneratePersonSlugAction $generatePersonSlugAction,
        protected SyncSlugRedirectAction $syncSlugRedirectAction,
        protected PublicDirectoryCacheVersion $publicDirectoryCacheVersion,
        protected PublicListingsCache $publicListingsCache,
        protected PersonSearchService $personSearchService,
    ) {}

    public function saved(Person $person): void
    {
        if (! $person->wasRecentlyCreated && ! $person->wasChanged()) {
            return;
        }

        $searchableNameChanged = $person->wasRecentlyCreated || $person->wasChanged([
            'name',
            'middle_name',
            'family_name',
        ]);

        if ($searchableNameChanged) {
            $this->personSearchService->syncPersonRecord($person);

            OwnerContext::withOwner(null, function () use ($person): void {
                $this->syncCurrentAndPreviousString(
                    $person->name,
                    $person->wasChanged('name') ? ($person->getPrevious()['name'] ?? null) : null,
                    fn (string $name): bool => $this->generatePersonSlugAction->syncPersonSlugsForName($name),
                    fn (string $name): bool => $this->generateEventSlugAction->syncEventSlugsForPersonName($name),
                );
            });
        } elseif ($person->wasChanged('status')) {
            $this->personSearchService->bustPublicSearchCache();
        }

        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->publicDirectoryCacheVersion->bumpPerson();
    }

    public function deleted(Person $person): void
    {
        $this->syncSlugRedirectAction->purgeForModel($person);
        $this->generatePersonSlugAction->syncPersonSlugsForName($person->name);
        $this->generateEventSlugAction->syncEventSlugsForPersonId((string) $person->getKey());
        $this->generateEventSlugAction->syncEventSlugsForPersonName($person->name);
        $this->personSearchService->purgePersonRecord($person);
        $this->publicListingsCache->bustHomepageStats();
        $this->publicListingsCache->bustMajlisListing();
        $this->publicDirectoryCacheVersion->bumpPerson();
    }
}
