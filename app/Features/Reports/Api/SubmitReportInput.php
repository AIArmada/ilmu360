<?php

declare(strict_types=1);

namespace App\Features\Reports\Api;

use App\Shared\Auth\AccountActor;

final readonly class SubmitReportInput
{
    /**
     * @param  array<int, mixed>  $evidence
     */
    public function __construct(
        public string $entityType,
        public string $entityId,
        public ?AccountActor $reporter,
        public string $reporterFingerprint,
        public string $category,
        public ?string $description = null,
        public array $evidence = [],
    ) {}
}
