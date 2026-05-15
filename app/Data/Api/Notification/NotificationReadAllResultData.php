<?php

namespace App\Data\Api\Notification;

use Spatie\LaravelData\Data;

class NotificationReadAllResultData extends Data
{
    public function __construct(
        public int $updated_count,
    ) {}

    public static function fromCount(int $updatedCount): self
    {
        return new self(
            updated_count: $updatedCount,
        );
    }
}
