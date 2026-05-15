<?php

namespace App\Data\Api\EventGoing;

use Spatie\LaravelData\Data;

class EventGoingResultData extends Data
{
    public function __construct(
        public string $message,
        public int $going_count,
    ) {}

    public static function fromOutcome(string $message, int $goingCount): self
    {
        return new self(
            message: $message,
            going_count: $goingCount,
        );
    }
}
