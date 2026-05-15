<?php

namespace App\Data\Api\EventSave;

use Spatie\LaravelData\Data;

class EventSaveResultData extends Data
{
    public function __construct(
        public string $message,
    ) {}

    public static function fromMessage(string $message): self
    {
        return new self(
            message: $message,
        );
    }
}
