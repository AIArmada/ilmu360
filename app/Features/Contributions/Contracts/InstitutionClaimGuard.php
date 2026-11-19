<?php

declare(strict_types=1);

namespace App\Features\Contributions\Contracts;

/**
 * Institution duplicate-claim guard used when submitting and approving
 * contribution requests.
 *
 * EnsureUniqueContributionCreateAction keeps the duplicate policy; the
 * approval action re-runs the same checks transactionally, so it consumes
 * them through this contract instead of importing the submission action.
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
