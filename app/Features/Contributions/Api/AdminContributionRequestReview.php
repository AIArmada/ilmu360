<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api;

use App\Models\User;

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
