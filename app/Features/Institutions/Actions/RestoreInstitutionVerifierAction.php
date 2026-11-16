<?php

declare(strict_types=1);

namespace App\Features\Institutions\Actions;

use App\Models\Institution;

final readonly class RestoreInstitutionVerifierAction
{
    /**
     * @param  list<string>  $institutionIds
     */
    public function handle(string $accountId, array $institutionIds): void
    {
        if ($institutionIds === []) {
            return;
        }

        Institution::query()
            ->whereKey($institutionIds)
            ->whereNull('verified_by')
            ->update(['verified_by' => $accountId]);
    }
}
