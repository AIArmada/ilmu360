<?php

namespace App\Data\Api\EventGoing;

use Spatie\LaravelData\Data;

class EventGoingStateData extends Data
{
    public function __construct(
        public bool $is_going,
        public int $going_count,
    ) {}

    public static function fromState(bool $isGoing, int $goingCount): self
    {
        return new self(
            is_going: $isGoing,
            going_count: $goingCount,
        );
    }
}
