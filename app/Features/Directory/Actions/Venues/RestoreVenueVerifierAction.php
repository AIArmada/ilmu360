<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Venues;

use App\Features\Directory\Support\DirectoryModel;
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

        DirectoryModel::query('venue')
            ->whereKey($venueIds)
            ->whereNull('verified_by')
            ->update(['verified_by' => $accountId]);
    }
}
