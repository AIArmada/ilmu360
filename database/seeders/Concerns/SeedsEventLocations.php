<?php

namespace Database\Seeders\Concerns;

use App\Enums\EventFormat;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Space;
use App\Models\Venue;
use Database\Seeders\SpaceSeeder;
use Illuminate\Support\Str;

trait SeedsEventLocations
{
    protected function seedEventSpaces(): void
    {
        $this->call([SpaceSeeder::class]);
    }

    protected function syncSeededEventLocation(
        Event $event,
        ?Institution $institution = null,
        ?Venue $venue = null,
    ): void {
        $deliveryMode = $event->delivery_mode;
        $isOnline = $deliveryMode === EventFormat::Online
            || (is_string($deliveryMode) && $deliveryMode === EventFormat::Online->value);

        if ($isOnline) {
            $event->syncLocation();

            return;
        }

        if ($institution instanceof Institution) {
            $space = $this->seededInstitutionEventSpace($institution);
            $event->syncLocation(null, [(string) $space->getKey()]);

            return;
        }

        if ($venue instanceof Venue) {
            $space = $this->seededVenueEventSpace($venue);
            $event->syncLocation((string) $venue->getKey(), [(string) $space->getKey()]);

            return;
        }

        $event->syncLocation();
    }

    private function seededInstitutionEventSpace(Institution $institution): Space
    {
        $space = Space::query()
            ->whereNull('venue_id')
            ->where('name', 'Dewan Utama')
            ->first();

        if (! $space instanceof Space) {
            $space = Space::query()->create([
                'name' => 'Dewan Utama',
                'slug' => 'dewan-utama',
                'space_type' => 'hall',
                'capacity' => 500,
                'status' => 'active',
                'visibility' => 'public',
            ]);
        }

        $institution->spaces()->syncWithoutDetaching([(string) $space->getKey()]);

        return $space;
    }

    private function seededVenueEventSpace(Venue $venue): Space
    {
        $space = Space::query()
            ->where('venue_id', (string) $venue->getKey())
            ->where('name', 'Dewan Utama')
            ->first();

        if ($space instanceof Space) {
            return $space;
        }

        return Space::query()->create([
            'venue_id' => (string) $venue->getKey(),
            'name' => 'Dewan Utama',
            'slug' => 'dewan-utama-venue-'.Str::lower((string) $venue->getKey()),
            'space_type' => 'hall',
            'capacity' => 500,
            'status' => 'active',
            'visibility' => 'public',
        ]);
    }
}
