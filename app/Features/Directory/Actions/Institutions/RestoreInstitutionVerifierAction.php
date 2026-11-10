<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Institutions;

use App\Features\Directory\Support\DirectoryModel;

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

        DirectoryModel::query('institution')
            ->whereKey($institutionIds)
            ->whereNull('verified_by')
            ->update(['verified_by' => $accountId]);
    }
}
