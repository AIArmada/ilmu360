<?php

declare(strict_types=1);

namespace App\Features\Reports\Api;

final readonly class ReportSubjectPresentation
{
    public function __construct(
        public string $subjectLabel,
        public string $subjectTitle,
        public string $redirectUrl,
        public ?string $profileImageUrl,
    ) {}
}
