<?php

declare(strict_types=1);

namespace App\Features\Persons\Api;

use App\Features\Reports\Api\ReportSubject;

interface PersonLookup
{
    public function resolveIdentifier(string $identifier): ?string;

    public function find(string $identifier): ?PersonSummary;

    public function findReportSubject(string $id): ?ReportSubject;

    public function resolveReportSubject(string $identifier): ?ReportSubject;

    /** @return list<string> */
    public function publicSearchIds(string $search): array;
}
