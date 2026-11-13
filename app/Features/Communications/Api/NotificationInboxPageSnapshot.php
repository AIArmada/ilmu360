<?php

declare(strict_types=1);

namespace App\Features\Communications\Api;

final readonly class NotificationInboxPageSnapshot
{
    /**
     * @param  list<NotificationInboxMessageSnapshot>  $messages
     */
    public function __construct(
        public array $messages,
        public int $page,
        public int $perPage,
        public int $total,
    ) {}
}
