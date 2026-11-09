<?php

declare(strict_types=1);

namespace App\Features\Reports\Api;

use App\Shared\Auth\AccountActor;

final readonly class TriageReportInput
{
    public function __construct(
        public string $reportId,
        public string $action,
        public ?string $resolutionNote,
        public AccountActor $actor,
    ) {}
}
