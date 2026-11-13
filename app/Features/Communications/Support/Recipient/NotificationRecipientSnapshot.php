<?php

declare(strict_types=1);

namespace App\Features\Communications\Support\Recipient;

final readonly class NotificationRecipientSnapshot
{
    public function __construct(
        public string $id,
        public string $recipientType,
        public ?string $email,
        public ?string $emailVerifiedAt,
        public ?string $timezone,
    ) {}
}
