<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/** Canonical admin persistence and initial-moderation workflow. */
interface AdminEventWriter
{
    /** @param array<string, mixed> $data */
    public function save(array $data, string $actorId, ?string $eventId = null): string;
}
