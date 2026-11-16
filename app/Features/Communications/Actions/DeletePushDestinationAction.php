<?php

declare(strict_types=1);

namespace App\Features\Communications\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

final readonly class DeletePushDestinationAction
{
    use AsAction;

    public function __construct(
        private FindPushDestinationByInstallationAction $findDestination,
    ) {}

    public function handle(string $accountId, string $installationId): void
    {
        $this->findDestination->handle($accountId, $installationId)?->delete();
    }
}
