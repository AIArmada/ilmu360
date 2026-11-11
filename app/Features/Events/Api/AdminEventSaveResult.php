<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Result of one admin event authoring save.
 */
final readonly class AdminEventSaveResult
{
    public function __construct(
        public string $eventId,
        public string $requestedStatus,
        public bool $created,
    ) {}
}
