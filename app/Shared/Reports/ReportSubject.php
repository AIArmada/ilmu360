<?php

declare(strict_types=1);

namespace App\Shared\Reports;

final readonly class ReportSubject
{
    public function __construct(
        public ReportSubjectType $type,
        public string $id,
        public string $label,
        public string $title,
        public ?string $slug,
        public ?string $publicUrl,
        public ?string $adminUrl,
        public ?string $imageUrl,
    ) {}
}
