<?php

declare(strict_types=1);

namespace App\Features\Persons\Actions;

use App\Models\Person;


final readonly class DetachPersonVerifierAction
{
    public function handle(string $accountId): void
    {
        Person::query()
            ->where('verified_by', $accountId)
            ->update(['verified_by' => null]);
    }
}
