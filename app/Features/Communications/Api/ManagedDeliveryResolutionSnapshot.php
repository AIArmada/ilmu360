<?php

declare(strict_types=1);

namespace App\Features\Communications\Api;

final readonly class ManagedDeliveryResolutionSnapshot
{
    public function __construct(
        public string $deliveryId,
        public string $status,
    ) {}
}
