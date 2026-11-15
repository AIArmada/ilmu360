<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

interface EventAccountOwnership
{
    /**
     * @return array{owned_event_ids: list<string>, event_submission_ids: list<string>}
     */
    public function snapshot(string $accountId): array;

    public function detach(string $accountId): void;

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function restore(string $accountId, array $snapshot): void;
}
