<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Venues;

use App\Features\Directory\Support\DirectoryModel;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class DetachVenueVerifierAction
{
    use AsAction;

    public function handle(string $accountId): void
    {
        DirectoryModel::query('venue')
            ->where('verified_by', $accountId)
            ->update(['verified_by' => null]);
    }
}
