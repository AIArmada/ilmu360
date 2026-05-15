<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationDeliveryStatus;

readonly class ChannelSendResult
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public NotificationDeliveryStatus $status,
        public ?string $providerMessageId = null,
        public array $meta = [],
    ) {}
}
