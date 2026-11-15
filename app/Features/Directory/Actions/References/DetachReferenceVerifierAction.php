<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\References;

use App\Features\Directory\Support\DirectoryModel;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class DetachReferenceVerifierAction
{
    use AsAction;

    public function handle(string $accountId): void
    {
        DirectoryModel::referenceQuery()
            ->where('verified_by', $accountId)
            ->update(['verified_by' => null]);
    }
}
