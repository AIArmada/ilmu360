<?php

declare(strict_types=1);

namespace App\Platform\Api\Member\Contracts;

/**
 * Contributes member workflow tool descriptors to the member manifest without
 * the platform knowing which feature owns the workflow.
 */
interface MemberWorkflowToolContributor
{
    public function priority(): int;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function tools(): array;
}
