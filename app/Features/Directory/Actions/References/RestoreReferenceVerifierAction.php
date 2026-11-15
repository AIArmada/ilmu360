<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\References;

use App\Features\Directory\Support\DirectoryModel;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class RestoreReferenceVerifierAction
{
    use AsAction;

    /** @param list<string> $referenceIds */
    public function handle(string $accountId, array $referenceIds): void
    {
        if ($referenceIds === []) {
            return;
        }

        DirectoryModel::referenceQuery()
            ->whereKey($referenceIds)
            ->whereNull('verified_by')
            ->update(['verified_by' => $accountId]);
    }
}
