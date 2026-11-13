<?php

declare(strict_types=1);

namespace App\Features\Rewards\Api;

interface ResolveDigestMailAmbiguity
{
    public function handle(
        string $deliveryId,
        string $decision,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        ?string $providerMessageId = null,
        ?string $reason = null,
    ): DigestMailResolutionSnapshot;
}
