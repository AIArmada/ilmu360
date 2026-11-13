<?php

declare(strict_types=1);

namespace App\Features\Communications\Actions;

use AIArmada\Communications\Models\CommunicationDestination;
use App\Features\Communications\Api\NotificationChannel;
use App\Shared\Auth\UserMorphType;
use Lorisleiva\Actions\Concerns\AsAction;

final class FindPushDestinationByInstallationAction
{
    use AsAction;

    public function __construct(
        private readonly UserMorphType $userMorphType,
    ) {}

    public function handle(string $accountId, string $installationId): ?CommunicationDestination
    {
        $destination = CommunicationDestination::query()
            ->where('recipient_type', $this->userMorphType->morphClass())
            ->where('recipient_id', $accountId)
            ->where('channel', NotificationChannel::Push->value)
            ->get()
            ->first(static fn (CommunicationDestination $destination): bool => $destination->address === $installationId);

        return $destination instanceof CommunicationDestination ? $destination : null;
    }
}
