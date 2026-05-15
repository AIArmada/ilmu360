<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Data;

class MessageResponseData extends Data
{
    public function __construct(
        public string $message,
    ) {}
}
