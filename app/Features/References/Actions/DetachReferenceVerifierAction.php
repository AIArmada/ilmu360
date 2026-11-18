<?php

declare(strict_types=1);

namespace App\Features\References\Actions;

use App\Features\References\Models\Reference;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class DetachReferenceVerifierAction
{
    use AsAction;

    public function handle(string $accountId): void
    {
        Reference::query()->withoutOwnerScope()
            ->where('verified_by', $accountId)
            ->update(['verified_by' => null]);
    }
}
