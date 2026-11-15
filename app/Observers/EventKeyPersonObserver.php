<?php

declare(strict_types=1);

namespace App\Observers;

use App\Features\Directory\Api\PublicDirectoryCacheVersions;
use App\Models\EventKeyPerson;
use App\Services\PublicListingsCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class EventKeyPersonObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        protected PublicDirectoryCacheVersions $publicDirectoryCacheVersions,
        protected PublicListingsCache $publicListingsCache,
    ) {}

    public function created(EventKeyPerson $eventKeyPerson): void
    {
        $this->publicListingsCache->bustHomepageStats();
        $this->publicDirectoryCacheVersions->bumpForEventKeyPerson();
    }

    public function updated(EventKeyPerson $eventKeyPerson): void
    {
        $this->publicListingsCache->bustHomepageStats();
        $this->publicDirectoryCacheVersions->bumpForEventKeyPerson();
    }

    public function deleted(EventKeyPerson $eventKeyPerson): void
    {
        $this->publicListingsCache->bustHomepageStats();
        $this->publicDirectoryCacheVersions->bumpForEventKeyPerson();
    }
}
