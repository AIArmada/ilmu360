<?php

declare(strict_types=1);

namespace App\Features\Communications\Services;

use AIArmada\Communications\Contracts\CommunicationManager;
use App\Features\Communications\Api\InAppNotificationPayload;
use App\Features\Communications\Api\NotificationDispatch;
use App\Features\Communications\Notifications\InAppNotification;
use App\Shared\Auth\AccountDirectory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class NotificationDispatchService implements NotificationDispatch
{
    public function __construct(
        private AccountDirectory $accounts,
        private CommunicationManager $communications,
    ) {}

    public function sendInAppToAccount(string $accountId, InAppNotificationPayload $payload): void
    {
        $notifiable = $this->accounts->resolveNotifiable($accountId);

        if (! $notifiable instanceof Authenticatable) {
            throw (new ModelNotFoundException)->setModel(Authenticatable::class, [$accountId]);
        }

        $this->communications->notify($notifiable, new InAppNotification($payload));
    }
}
