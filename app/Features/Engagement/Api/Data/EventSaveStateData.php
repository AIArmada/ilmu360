<?php

declare(strict_types=1);

namespace App\Features\Engagement\Api\Data;

final readonly class EventSaveStateData
{
    public function __construct(
        public bool $is_saved,
        public int $saves_count,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
