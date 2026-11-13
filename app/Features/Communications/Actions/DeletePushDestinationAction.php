<?php

declare(strict_types=1);

namespace App\Features\Communications\Actions;

use AIArmada\Communications\Models\CommunicationDestination;
use Lorisleiva\Actions\Concerns\AsAction;

final class DeletePushDestinationAction
{
    use AsAction;

    public function __construct(
        private readonly FindPushDestinationByInstallationAction $findDestination,
    ) {}

    public function handle(string $accountId, string $installationId): void
    {
        $this->findDestination->handle($accountId, $installationId)?->delete();
    }
}
