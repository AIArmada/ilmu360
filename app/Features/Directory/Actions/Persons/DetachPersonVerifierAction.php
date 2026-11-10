<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Persons;

use App\Features\Directory\Support\DirectoryModel;

final readonly class DetachPersonVerifierAction
{
    public function handle(string $accountId): void
    {
        DirectoryModel::query('person')
            ->where('verified_by', $accountId)
            ->update(['verified_by' => null]);
    }
}
