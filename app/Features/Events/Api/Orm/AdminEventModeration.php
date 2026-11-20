<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

use App\Models\User;

/**
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Events/FeatureServiceProvider.php, Events/EntryPoints
 */
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
