<?php

declare(strict_types=1);

namespace App\Platform\Auditing;

final readonly class AuditRecord
{
    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function __construct(
        public string $event,
        public string $actorType,
        public string $actorId,
        public string $subjectType,
        public string $subjectId,
        public array $oldValues,
        public array $newValues,
        public string $url,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}
}
