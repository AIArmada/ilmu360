<?php

namespace App\Data\Api\EventSave;

use Spatie\LaravelData\Data;

class EventSaveStateData extends Data
{
    public function __construct(
        public bool $is_saved,
        public int $saves_count,
    ) {}

    public static function fromState(bool $isSaved, int $savesCount): self
    {
        return new self(
            is_saved: $isSaved,
            saves_count: $savesCount,
        );
    }
}
