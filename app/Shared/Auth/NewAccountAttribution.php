<?php

declare(strict_types=1);

namespace App\Shared\Auth;

/** Registration transport integration for the Identity signup workflow. */
interface NewAccountAttribution
{
    public function recordForNewAccount(
        string $accountId,
        SignupAttributionContext $context,
        bool $wasRecentlyCreated,
    ): void;
}
