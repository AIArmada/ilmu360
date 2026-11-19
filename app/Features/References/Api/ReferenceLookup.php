<?php

declare(strict_types=1);

namespace App\Features\References\Api;

use App\Features\Reports\Api\ReportSubject;

interface ReferenceLookup
{
    public function resolveIdentifier(string $identifier): ?string;

    public function find(string $identifier): ?ReferenceSummary;

    public function findReportSubject(string $id): ?ReportSubject;

    public function resolveReportSubject(string $identifier): ?ReportSubject;
}
