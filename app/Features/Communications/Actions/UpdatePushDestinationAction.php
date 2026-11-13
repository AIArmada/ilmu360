<?php

declare(strict_types=1);

namespace App\Features\Communications\Actions;

use AIArmada\Communications\Models\CommunicationDestination;
use App\Features\Communications\Api\PushDestinationDetails;
use Lorisleiva\Actions\Concerns\AsAction;

final class UpdatePushDestinationAction
{
    use AsAction;

    public function __construct(
        private readonly FindPushDestinationByInstallationAction $findDestination,
        private readonly SyncPushDestinationDetailsAction $syncDestination,
    ) {}

    public function handle(string $accountId, string $installationId, PushDestinationDetails $details): ?CommunicationDestination
    {
        $destination = $this->findDestination->handle($accountId, $installationId);

        if (! $destination instanceof CommunicationDestination) {
            return null;
        }

        $this->syncDestination->handle($destination, $details);

        return $destination->fresh() ?? $destination;
    }
}
