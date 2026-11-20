<?php

declare(strict_types=1);

namespace App\Features\Registration\Api\Data;

final readonly class EventRegistrationStatusData
{
    public function __construct(
        public bool $is_registered,
        public ?EventRegistrationData $registration,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_registered' => $this->is_registered,
            'registration' => $this->registration?->toArray(),
        ];
    }
}
