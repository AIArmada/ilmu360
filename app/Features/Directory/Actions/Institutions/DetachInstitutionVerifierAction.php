<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Institutions;

use App\Features\Directory\Support\DirectoryModel;

final readonly class DetachInstitutionVerifierAction
{
    public function handle(string $accountId): void
    {
        DirectoryModel::query('institution')
            ->where('verified_by', $accountId)
            ->update(['verified_by' => null]);
    }
}
