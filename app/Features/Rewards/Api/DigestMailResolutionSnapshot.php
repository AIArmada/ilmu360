<?php

declare(strict_types=1);

namespace App\Features\Rewards\Api;

final readonly class DigestMailResolutionSnapshot
{
    public function __construct(
        public string $deliveryId,
        public string $status,
    ) {}
}
