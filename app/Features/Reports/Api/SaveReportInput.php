<?php

declare(strict_types=1);

namespace App\Features\Reports\Api;

final readonly class SaveReportInput
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public array $attributes,
        public ?string $reportId = null,
    ) {}
}
