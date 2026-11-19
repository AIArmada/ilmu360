<?php

declare(strict_types=1);

namespace App\Features\Events\Observers;

use App\Features\Discovery\Api\PublicListingsCacheInvalidator;
use App\Features\Events\Models\EventKeyPerson;
use App\Features\Persons\Api\PersonListingCacheVersion;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class EventKeyPersonObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        protected PersonListingCacheVersion $personCacheVersion,
        protected PublicListingsCacheInvalidator $publicListingsCache,
    ) {}

    public function created(EventKeyPerson $eventKeyPerson): void
    {
        $this->publicListingsCache->bustHomepageStats();
        $this->personCacheVersion->bump();
    }

    public function updated(EventKeyPerson $eventKeyPerson): void
    {
        $this->publicListingsCache->bustHomepageStats();
        $this->personCacheVersion->bump();
    }

    public function deleted(EventKeyPerson $eventKeyPerson): void
    {
        $this->publicListingsCache->bustHomepageStats();
        $this->personCacheVersion->bump();
    }
}
