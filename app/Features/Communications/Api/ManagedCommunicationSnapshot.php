<?php

declare(strict_types=1);

namespace App\Features\Communications\Api;

use Carbon\CarbonImmutable;

final readonly class ManagedCommunicationSnapshot
{
    /**
     * @param  array<string, string>  $channelStatuses
     */
    public function __construct(
        public string $communicationId,
        public ?CarbonImmutable $queuedAt,
        public array $channelStatuses,
        public bool $hasDelivered,
        public bool $hasInFlight,
        public bool $hasAmbiguous,
        public bool $allPreSend,
    ) {}
}
