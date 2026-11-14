<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

final readonly class EventMediaExtractionSource
{
    public function __construct(
        public string $content,
        public string $mimeType,
        public string $fileName,
    ) {}
}
