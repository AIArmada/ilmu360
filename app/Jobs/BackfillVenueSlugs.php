<?php

namespace App\Jobs;

use App\Features\Directory\Api\VenueWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BackfillVenueSlugs implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  list<string>  $venueIds
     */
    public function __construct(public array $venueIds = []) {}

    public function handle(VenueWorkflow $venueWorkflow): void
    {
        $venueWorkflow->backfillSlugs($this->venueIds);
    }
}
