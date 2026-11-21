<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use App\Shared\Auth\AccountActor;

/**
 * Event-owned moderation interface exposed through the account actor contract.
 * Neither Eloquent models nor builders cross this feature boundary.
 */
interface AdminEventModeration
{
    public function canModerate(?AccountActor $actor = null): bool;

    /** @return array<string, mixed> */
    public function schema(string $recordKey, ?AccountActor $actor = null): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function moderate(string $recordKey, array $payload, ?AccountActor $actor = null): array;
}
