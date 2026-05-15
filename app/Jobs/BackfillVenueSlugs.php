<?php

namespace App\Jobs;

use App\Actions\Venues\GenerateVenueSlugAction;
use App\Models\Venue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

class BackfillVenueSlugs implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  list<string>  $venueIds
     */
    public function __construct(public array $venueIds = []) {}

    public function handle(GenerateVenueSlugAction $generateVenueSlugAction): void
    {
        $query = Venue::query()
            ->with([
                'address.country',
                'address.state',
                'address.district',
                'address.subdistrict',
            ])
            ->orderBy('name')
            ->orderBy('id');

        if ($this->venueIds !== []) {
            $query->whereIn('id', $this->venueIds);
        }

        /** @var Collection<int, Venue> $venues */
        $venues = $query->get();

        foreach ($venues as $venue) {
            $slug = $generateVenueSlugAction->forVenue($venue);

            if ($venue->slug === $slug) {
                continue;
            }

            Venue::withoutTimestamps(function () use ($venue, $slug): void {
                $venue->forceFill([
                    'slug' => $slug,
                ])->saveQuietly();
            });
        }
    }
}
