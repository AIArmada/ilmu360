<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/** Scalar attribution facts needed by the outbound touchpoint writer. */
final readonly class OutboundShareAttributionReceipt
{
    public function __construct(
        public string $attributionId,
        public ?string $visitorKey,
        public ?string $origin,
    ) {}
}
