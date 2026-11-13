<?php

declare(strict_types=1);

namespace App\Features\Communications\Notifications;

use AIArmada\Communications\Contracts\CommunicationContextAware;
use AIArmada\Communications\Traits\HasCommunicationContext;
use App\Features\Communications\Api\DeliveryFencing;
use App\Features\Communications\Api\InAppNotificationMessage;
use App\Features\Communications\Api\InAppNotificationPayload;
use App\Features\Communications\Api\ManagedNotificationMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

final class ManagedNotification extends Notification implements CommunicationContextAware, InAppNotificationMessage
{
    use HasCommunicationContext;

    public function __construct(public readonly ManagedNotificationMessage $message) {}

    /**
     * @param  mixed  $notifiable
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return $this->message->channels;
    }

    public function inAppPayload(): InAppNotificationPayload
    {
        return $this->message->inAppPayload;
    }

    /** @return array<string, mixed> */
    public function toArray(mixed $notifiable): array
    {
        return $this->inAppPayload()->toArray();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject((string) __($this->message->mailSubjectTranslationKey))
            ->line((string) __($this->message->mailBodyTranslationKey, $this->message->mailReplacements));
    }

    /** @return array<string, mixed> */
    public function operationSnapshot(): array
    {
        return $this->message->operationSnapshot;
    }

    public function failed(Throwable $exception): void
    {
        if ($this->communicationId === null) {
            return;
        }

        try {
            $message = $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class;
            app(DeliveryFencing::class)->recordNotificationFailure(
                $this->communicationId,
                $this->deliveryIdsByChannel,
                $message,
            );
        } catch (Throwable) {
            // Failure callbacks must not replace the original delivery error.
        }
    }
}
