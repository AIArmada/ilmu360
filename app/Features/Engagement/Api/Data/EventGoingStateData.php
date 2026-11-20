<?php

declare(strict_types=1);

namespace App\Features\Engagement\Api\Data;

final readonly class EventGoingStateData
{
    public function __construct(
        public bool $is_going,
        public int $going_count,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
