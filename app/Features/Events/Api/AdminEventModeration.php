<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use App\Models\User;

interface AdminEventModeration
{
    public function canModerate(?User $actor = null): bool;

    /** @return array<string, mixed> */
    public function schema(string $recordKey, ?User $actor = null): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function moderate(string $recordKey, array $payload, ?User $actor = null): array;
}
