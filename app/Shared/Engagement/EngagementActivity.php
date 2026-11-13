<?php

declare(strict_types=1);

namespace App\Shared\Engagement;

use Carbon\CarbonImmutable;

/**
 * Immutable read projection of one package engagement source record.
 *
 * Notification and reward readers consume this instead of importing the
 * package bookmark/response/follow models directly.
 */
final readonly class EngagementActivity
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $actorType,
        public string $actorId,
        public ?string $status,
        public ?CarbonImmutable $occurredAt,
        public array $metadata = [],
    ) {}
}
