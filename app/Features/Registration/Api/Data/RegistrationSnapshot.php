<?php

declare(strict_types=1);

namespace App\Features\Registration\Api\Data;

/** Stable Registration-owned snapshot for admission, checkout and account readers. */
final readonly class RegistrationSnapshot
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $id,
        public string $eventId,
        public ?string $eventTitle,
        public ?string $eventStartsAt,
        public ?string $eventStatus,
        public ?string $createdAt,
        public ?string $registeredAt,
        public ?string $registrantType,
        public ?string $registrantId,
        public ?string $status,
        public ?string $externalOrderId,
        public ?string $eventOccurrenceId,
        public ?string $eventSessionId,
        public ?string $primaryParticipantUserId,
        public int $totalAmount,
        public ?string $paymentStatus,
        public ?string $cancelledAt,
        public ?string $fraudStatus,
        public array $metadata,
        public bool $isAmbiguousDevice,
        public bool $isBot,
    ) {}
}
