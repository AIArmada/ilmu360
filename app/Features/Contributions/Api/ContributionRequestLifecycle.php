<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api;

use App\Shared\Contributions\Enums\ContributionSubjectType;

/**
 * Public contribution-request lifecycle boundary.
 *
 * Root transport and admin/MCP callers pass stable scalar identities and the
 * feature resolves accounts, morph entities and requests internally. Submit
 * methods return the new request identifier; review and cancel complete
 * without returning mutable Eloquent state. Callers keep their own
 * authorization resolution (Resolve* actions) before invoking this boundary.
 */
interface ContributionRequestLifecycle
{
    /**
     * @param  array<string, mixed>  $proposedData
     */
    public function submitCreate(
        ContributionSubjectType $subjectType,
        string $proposerId,
        array $proposedData,
        ?string $proposerNote = null,
        ?string $entityType = null,
        ?string $entityId = null,
    ): string;

    /**
     * @param  array<string, mixed>  $proposedData
     */
    public function submitUpdate(
        string $entityType,
        string $entityId,
        string $proposerId,
        array $proposedData,
        ?string $proposerNote = null,
    ): string;

    public function approve(string $requestId, string $reviewerId, ?string $reviewerNote = null): void;

    public function reject(string $requestId, string $reviewerId, string $reasonCode, ?string $reviewerNote = null): void;

    public function cancel(string $requestId, string $proposerId): void;

    /**
     * Delete requests one row at a time and return the deleted count.
     *
     * @param  list<string>  $requestIds
     */
    public function delete(array $requestIds): int;
}
