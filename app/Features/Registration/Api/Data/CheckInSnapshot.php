<?php

declare(strict_types=1);

namespace App\Features\Registration\Api\Data;

/** Stable Registration-owned check-in snapshot for account and reward readers. */
final readonly class CheckInSnapshot
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $id,
        public string $eventId,
        public ?string $eventTitle,
        public ?string $eventStartsAt,
        public ?string $createdAt,
        public ?string $checkedInAt,
        public ?string $checkInSource,
        public ?string $verifiedByUserId,
        public ?string $verifiedByName,
        public ?string $attendeeType,
        public ?string $attendeeId,
        public ?string $attendanceType,
        public ?string $status,
        public ?string $cancelledAt,
        public ?string $fraudStatus,
        public array $metadata,
        public bool $isAmbiguousDevice,
        public bool $isBot,
    ) {}
}
