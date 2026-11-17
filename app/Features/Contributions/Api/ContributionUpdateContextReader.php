<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api;

/** Read-only update form context; model hydration remains in the transport. */
interface ContributionUpdateContextReader
{
    /**
     * @return array{
     *     entity_type: string,
     *     entity_id: string,
     *     initial_state: array<string, mixed>,
     *     contract: array{
     *         accepts_partial_updates: bool,
     *         fields: list<array<string, mixed>>,
     *         conditional_rules: list<array<string, mixed>>,
     *         direct_edit_media_fields: list<string>
     *     }
     * }
     */
    public function resolve(string $subjectType, string $subjectId): array;
}
