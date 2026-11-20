<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api\Orm;

use App\Models\User;

/**
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Contributions/FeatureServiceProvider.php, Contributions/EntryPoints
 */
interface AdminContributionRequestReview
{
    public function canReview(?User $actor = null): bool;

    /** @return array<string, mixed> */
    public function schema(string $recordKey, ?User $actor = null): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function review(string $recordKey, array $payload, ?User $actor = null): array;
}
