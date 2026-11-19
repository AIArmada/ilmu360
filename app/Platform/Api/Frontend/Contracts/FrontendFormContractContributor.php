<?php

declare(strict_types=1);

namespace App\Platform\Api\Frontend\Contracts;

use App\Models\User;

/**
 * Contributes one or more frontend form-contract families to the neutral
 * registry.
 *
 * A contributor owns the exact payload keys, field order, schema metadata,
 * validation rules, catalogs and nullability for its feature. The platform
 * never reaches into owner namespaces to assemble those payloads.
 */
interface FrontendFormContractContributor
{
    /**
     * Whether this contributor owns the contract family key.
     */
    public function supports(string $key): bool;

    /**
     * Manifest fragments merged at the platform boundary, or null when this
     * contributor publishes no manifest data.
     *
     * @return array<string, mixed>|null
     */
    public function manifest(?User $user): ?array;

    /**
     * Build the contract payload for a supported family key.
     *
     * Context values are family-specific; `user` and `subjectType` are the
     * shared inputs exposed by the public service API.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function contract(string $key, array $context): array;
}
