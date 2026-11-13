<?php

declare(strict_types=1);

namespace App\Features\Communications\Actions;

use AIArmada\Communications\Models\CommunicationDestination;
use App\Features\Communications\Api\NotificationChannel;
use App\Shared\Auth\UserMorphType;
use Lorisleiva\Actions\Concerns\AsAction;

final class RemovePushDestinationAction
{
    use AsAction;

    public function __construct(
        private readonly UserMorphType $userMorphType,
    ) {}

    public function handle(string $accountId, string $destinationId): bool
    {
        $destination = CommunicationDestination::query()
            ->where('recipient_type', $this->userMorphType->morphClass())
            ->where('recipient_id', $accountId)
            ->where('channel', NotificationChannel::Push->value)
            ->whereKey($destinationId)
            ->first();

        if (! $destination instanceof CommunicationDestination) {
            return false;
        }

        $destination->delete();

        return true;
    }
}
