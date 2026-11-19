<?php

declare(strict_types=1);

namespace App\Features\Identity\Actions;

use App\Features\Identity\Api\NewAccountAttribution;
use App\Features\Identity\Api\SignupAttributionContext;
use App\Features\Identity\Api\SignupAttributionWorkflow;

/** Consume Identity's canonical signup workflow from a generic auth transport. */
final readonly class RecordNewAccountAttributionAction implements NewAccountAttribution
{
    public function __construct(private SignupAttributionWorkflow $workflow) {}

    public function recordForNewAccount(
        string $accountId,
        SignupAttributionContext $context,
        bool $wasRecentlyCreated,
    ): void {
        $this->workflow->recordForNewAccount($accountId, $context, $wasRecentlyCreated);
    }
}
