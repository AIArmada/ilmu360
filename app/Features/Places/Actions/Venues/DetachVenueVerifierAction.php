<?php

declare(strict_types=1);

namespace App\Features\Places\Actions\Venues;

use App\Features\Places\Models\Venue;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class DetachVenueVerifierAction
{
    use AsAction;

    public function handle(string $accountId): void
    {
        Venue::query()
            ->where('verified_by', $accountId)
            ->update(['verified_by' => null]);
    }
}
