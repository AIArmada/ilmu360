<?php

namespace App\Listeners\Notifications;

use App\Enums\NotificationChannel;
use App\Models\User;
use App\Notifications\Channels\Exceptions\ChannelDeliveryException;
use App\Notifications\NotificationCenterMessage;
use App\Services\Notifications\NotificationDeliveryLogger;
use App\Services\Notifications\NotificationEngine;
use Illuminate\Notifications\Events\NotificationFailed;

class HandleNotificationFailed
{
    public function __construct(
        protected NotificationDeliveryLogger $logger,
        protected NotificationEngine $engine,
    ) {}

    public function handle(NotificationFailed $event): void
    {
        if (! $event->notification instanceof NotificationCenterMessage || ! $event->notifiable instanceof User) {
            return;
        }

        $channel = $event->notification->targetChannel;
        $exception = data_get($event->data, 'exception');

        if ($exception instanceof ChannelDeliveryException) {
            $this->logger->logChannelResults(
                $event->notifiable,
                $event->notification,
                $channel,
                $exception->results,
                $exception->provider,
            );
        } else {
            $this->logger->logChannelFailure(
                $event->notifiable,
                $event->notification,
                $channel,
                ['data' => $event->data],
            );
        }

        if ($channel !== NotificationChannel::InApp) {
            $this->engine->queueFallbackForNotification($event->notifiable, $event->notification);
        }
    }
}
