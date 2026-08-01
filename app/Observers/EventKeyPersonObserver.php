<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\EventKeyPerson;
use App\Support\Cache\PublicDirectoryCacheVersion;
use App\Support\Cache\PublicListingsCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class EventKeyPersonObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        protected PublicDirectoryCacheVersion $publicDirectoryCacheVersion,
        protected PublicListingsCache $publicListingsCache,
    ) {}

    public function created(EventKeyPerson $eventKeyPerson): void
    {
        $this->publicListingsCache->bustHomepageStats();
        $this->publicDirectoryCacheVersion->bumpForEventKeyPerson($eventKeyPerson);
    }

    public function updated(EventKeyPerson $eventKeyPerson): void
    {
        $this->publicListingsCache->bustHomepageStats();
        $this->publicDirectoryCacheVersion->bumpForEventKeyPerson($eventKeyPerson);
    }

    public function deleted(EventKeyPerson $eventKeyPerson): void
    {
        $this->publicListingsCache->bustHomepageStats();
        $this->publicDirectoryCacheVersion->bumpForEventKeyPerson($eventKeyPerson);
    }
}
