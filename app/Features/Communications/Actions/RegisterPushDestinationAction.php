<?php

declare(strict_types=1);

namespace App\Features\Communications\Actions;

use AIArmada\Communications\Models\CommunicationDestination;
use App\Features\Communications\Api\NotificationChannel;
use App\Features\Communications\Api\PushDestinationDetails;
use App\Shared\Auth\UserMorphType;
use Lorisleiva\Actions\Concerns\AsAction;

final class RegisterPushDestinationAction
{
    use AsAction;

    public function __construct(
        private readonly FindPushDestinationByInstallationAction $findDestination,
        private readonly SyncPushDestinationDetailsAction $syncDestination,
        private readonly UserMorphType $userMorphType,
    ) {}

    public function handle(string $accountId, string $installationId, PushDestinationDetails $details): CommunicationDestination
    {
        $destination = $this->findDestination->handle($accountId, $installationId);

        if (! $destination instanceof CommunicationDestination) {
            $destination = new CommunicationDestination;
            $destination->forceFill([
                'recipient_type' => $this->userMorphType->morphClass(),
                'recipient_id' => $accountId,
                'channel' => NotificationChannel::Push->value,
                'address' => $installationId,
            ]);
        }

        $destination->forceFill(['is_primary' => false]);

        return $this->syncDestination->handle($destination, $details);
    }
}
