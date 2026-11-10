<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Persons;

use App\Features\Directory\Support\DirectoryModel;

final readonly class RestorePersonVerifierAction
{
    /**
     * @param  list<string>  $personIds
     */
    public function handle(string $accountId, array $personIds): void
    {
        if ($personIds === []) {
            return;
        }

        DirectoryModel::query('person')
            ->whereKey($personIds)
            ->whereNull('verified_by')
            ->update(['verified_by' => $accountId]);
    }
}
