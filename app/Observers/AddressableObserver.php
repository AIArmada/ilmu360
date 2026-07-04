<?php

namespace App\Observers;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\Addressable;
use App\Actions\Events\GenerateEventSlugAction;
use App\Actions\Institutions\GenerateInstitutionSlugAction;
use App\Actions\Speakers\GenerateSpeakerSlugAction;
use App\Actions\Venues\GenerateVenueSlugAction;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\Venue;
use App\Support\Cache\PublicDirectoryCacheVersion;
use App\Support\Cache\PublicListingsCache;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class AddressableObserver
{
    public function __construct(
        private readonly GenerateEventSlugAction $generateEventSlugAction,
        private readonly GenerateInstitutionSlugAction $generateInstitutionSlugAction,
        private readonly GenerateSpeakerSlugAction $generateSpeakerSlugAction,
        private readonly GenerateVenueSlugAction $generateVenueSlugAction,
        private readonly PublicDirectoryCacheVersion $publicDirectoryCacheVersion,
        private readonly PublicListingsCache $publicListingsCache,
    ) {}

    public function created(Addressable $addressable): void
    {
        $addressable->loadMissing('address');

        $address = $addressable->address;

        if ($address instanceof Address) {
            $this->publicDirectoryCacheVersion->bumpForAddress($address);
        }

        $this->syncAddressable($addressable);
    }

    public function deleted(Addressable $addressable): void
    {
        $addressable->loadMissing('address');

        $address = $addressable->address;

        if ($address instanceof Address) {
            $this->publicDirectoryCacheVersion->bumpForAddress($address);
        }

        $this->syncAddressable($addressable);
    }

    private function syncAddressable(Addressable $addressable): void
    {
        $addressable->loadMissing('addressable');

        $subject = $addressable->addressable;

        if ($subject instanceof Institution) {
            $this->generateInstitutionSlugAction->syncInstitutionSlugsForName($subject->name);
            $this->syncSearchableModel($subject);
            $this->syncSearchableEvents($subject->events()->get(['events.*']));
            $this->publicListingsCache->bustMajlisListing();

            return;
        }

        if ($subject instanceof Speaker) {
            $this->generateSpeakerSlugAction->syncSpeakerSlugsForName($subject->name);
            $this->generateEventSlugAction->syncEventSlugsForSpeakerName($subject->name);
            $this->syncSearchableModel($subject);
            $this->publicListingsCache->bustMajlisListing();

            return;
        }

        if ($subject instanceof Venue) {
            $this->generateVenueSlugAction->syncVenueSlugsForName($subject->name);
            $this->syncSearchableEvents($subject->events()->get(['events.*']));
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
