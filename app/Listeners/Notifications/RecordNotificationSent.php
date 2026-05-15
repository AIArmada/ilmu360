<?php

namespace App\Listeners\Notifications;

use App\Enums\NotificationChannel;
use App\Models\User;
use App\Notifications\NotificationCenterMessage;
use App\Services\Notifications\NotificationDeliveryLogger;
use Illuminate\Notifications\Events\NotificationSent;

class RecordNotificationSent
{
    public function __construct(
        protected NotificationDeliveryLogger $logger,
    ) {}

    public function handle(NotificationSent $event): void
    {
        if (! $event->notification instanceof NotificationCenterMessage || ! $event->notifiable instanceof User) {
            return;
        }

        $channel = $event->notification->targetChannel;

        if ($channel === NotificationChannel::Email) {
            $this->logger->logMailSent($event->notifiable, $event->notification);

            return;
        }

        if ($channel === NotificationChannel::InApp) {
            $this->logger->logDatabaseSent($event->notifiable, $event->notification, $event->response);

            return;
        }

        if (in_array($channel, [NotificationChannel::Push, NotificationChannel::Whatsapp], true) && is_array($event->response)) {
            $results = $event->response['results'] ?? null;

            if (is_array($results)) {
                $this->logger->logChannelResults(
                    $event->notifiable,
                    $event->notification,
                    $channel,
                    $results,
                    is_string($event->response['provider'] ?? null) ? $event->response['provider'] : null,
                );
            }
        }
    }
}
