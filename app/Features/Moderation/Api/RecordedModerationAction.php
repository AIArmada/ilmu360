<?php

declare(strict_types=1);

namespace App\Features\Moderation\Api;

final readonly class RecordedModerationAction
{
    public function __construct(
        public string $id,
        public ?string $notes,
    ) {}
}
