<?php

declare(strict_types=1);

namespace App\Features\Places\Actions\Venues;

use App\Models\Venue;

use Lorisleiva\Actions\Concerns\AsAction;

final readonly class RestoreVenueVerifierAction
{
    use AsAction;

    /** @param list<string> $venueIds */
    public function handle(string $accountId, array $venueIds): void
    {
        if ($venueIds === []) {
            return;
        }

        Venue::query()
            ->whereKey($venueIds)
            ->whereNull('verified_by')
            ->update(['verified_by' => $accountId]);
    }
}
