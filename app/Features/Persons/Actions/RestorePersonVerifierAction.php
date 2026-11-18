<?php

declare(strict_types=1);

namespace App\Features\Persons\Actions;

use App\Features\Persons\Models\Person;

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

        Person::query()
            ->whereKey($personIds)
            ->whereNull('verified_by')
            ->update(['verified_by' => $accountId]);
    }
}
