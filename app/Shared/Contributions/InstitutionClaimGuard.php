<?php

declare(strict_types=1);

namespace App\Shared\Contributions;

/**
 * Institution duplicate-claim guard used when submitting and approving
 * contribution requests.
 *
 * The conventional submission guard keeps the duplicate policy; the approval
 * action re-runs the same checks transactionally, so it consumes them through
 * this contract instead of importing the root action.
 */
interface InstitutionClaimGuard
{
    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function normalizeAddress(array $state): array;

    /**
     * @param  array<string, mixed>  $state
     */
    public function institutionDuplicateExists(array $state, ?string $excludeInstitutionId = null, bool $preNormalizedAddress = false): bool;

    /**
     * @param  array<string, mixed>  $state
     */
    public function pendingInstitutionClaimExists(array $state, ?string $excludeRequestId = null, bool $preNormalizedAddress = false): bool;
}
