<?php

declare(strict_types=1);

namespace App\Features\References\Actions;

use App\Models\Reference;
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

        Reference::query()->withoutOwnerScope()
            ->whereKey($referenceIds)
            ->whereNull('verified_by')
            ->update(['verified_by' => $accountId]);
    }
}
