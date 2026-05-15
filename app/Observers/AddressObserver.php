<?php

namespace App\Observers;

use App\Actions\Events\GenerateEventSlugAction;
use App\Actions\Institutions\GenerateInstitutionSlugAction;
use App\Actions\Speakers\GenerateSpeakerSlugAction;
use App\Actions\Venues\GenerateVenueSlugAction;
use App\Models\Address;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\Venue;
use App\Support\Cache\PublicDirectoryCacheVersion;
use App\Support\Cache\PublicListingsCache;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class AddressObserver
{
    public function __construct(
        protected GenerateEventSlugAction $generateEventSlugAction,
        protected GenerateInstitutionSlugAction $generateInstitutionSlugAction,
        protected GenerateSpeakerSlugAction $generateSpeakerSlugAction,
        protected GenerateVenueSlugAction $generateVenueSlugAction,
        protected PublicDirectoryCacheVersion $publicDirectoryCacheVersion,
        protected PublicListingsCache $publicListingsCache,
    ) {}

    public function saved(Address $address): void
    {
        $this->syncInstitutionSlug($address);
        $this->publicDirectoryCacheVersion->bumpForAddress($address);
    }

    public function deleted(Address $address): void
    {
        $this->syncInstitutionSlug($address);
        $this->publicDirectoryCacheVersion->bumpForAddress($address);
    }

    private function syncInstitutionSlug(Address $address): void
    {
        $address->loadMissing('addressable');

        $addressable = $address->addressable;

        if ($addressable instanceof Institution) {
            $this->generateInstitutionSlugAction->syncInstitutionSlugsForName($addressable->name);
            $this->syncSearchableModel($addressable);
            $this->syncSearchableEvents($addressable->events()->get(['events.*']));
            $this->publicListingsCache->bustMajlisListing();

            return;
        }

        if ($addressable instanceof Speaker) {
            $this->generateSpeakerSlugAction->syncSpeakerSlugsForName($addressable->name);
            $this->generateEventSlugAction->syncEventSlugsForSpeakerName($addressable->name);
            $this->syncSearchableModel($addressable);
            $this->publicListingsCache->bustMajlisListing();

            return;
        }

        if ($addressable instanceof Venue) {
            $this->generateVenueSlugAction->syncVenueSlugsForName($addressable->name);
            $this->syncSearchableEvents($addressable->events()->get(['events.*']));
            $this->publicListingsCache->bustMajlisListing();
        }
    }

    private function syncSearchableModel(Institution|Speaker $model): void
    {
        if ($model->shouldBeSearchable()) {
            $model->searchable();

            return;
        }

        $model->unsearchable();
    }

    /**
     * @param  EloquentCollection<int, Event>  $events
     */
    private function syncSearchableEvents(EloquentCollection $events): void
    {
        $searchableEvents = $events
            ->filter(fn (Event $event): bool => $event->shouldBeSearchable())
            ->values();

        if ($searchableEvents->isNotEmpty()) {
            $searchableEvents->searchable();
        }

        $unsearchableEvents = $events
            ->reject(fn (Event $event): bool => $event->shouldBeSearchable())
            ->values();

        if ($unsearchableEvents->isNotEmpty()) {
            $unsearchableEvents->unsearchable();
        }
    }
}
