<?php

declare(strict_types=1);

namespace App\Features\Identity\Api;

/** Registration transport integration for the Identity signup workflow. */
interface NewAccountAttribution
{
    public function recordForNewAccount(
        string $accountId,
        SignupAttributionContext $context,
        bool $wasRecentlyCreated,
    ): void;
}
