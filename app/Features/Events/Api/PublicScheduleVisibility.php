<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

interface PublicScheduleVisibility
{
    public function isPublicOccurrence(string $occurrenceId): bool;
}
