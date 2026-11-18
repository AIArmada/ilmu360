<?php

declare(strict_types=1);

namespace App\Features\Institutions\Actions;

use App\Features\Institutions\Models\Institution;

final readonly class DetachInstitutionVerifierAction
{
    public function handle(string $accountId): void
    {
        Institution::query()
            ->where('verified_by', $accountId)
            ->update(['verified_by' => null]);
    }
}
