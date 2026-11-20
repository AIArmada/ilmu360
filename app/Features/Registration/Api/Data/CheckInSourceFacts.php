<?php

declare(strict_types=1);

namespace App\Features\Registration\Api\Data;

/** Immutable check-in source snapshot for share-outcome attribution. */
final readonly class CheckInSourceFacts
{
    public function __construct(
        public string $id,
        public ?string $eventRegistrationId,
        public ?string $checkInSource,
        public ?string $createdAt,
    ) {}
}
