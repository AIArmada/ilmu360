<?php

declare(strict_types=1);

namespace App\Features\Reports\Api;

interface ReportReader
{
    public function find(string $reportId): ?ReportSnapshot;

    /** @return list<ReportSnapshot> */
    public function forReporter(string $reporterId, ?int $limit = null): array;

    public function pageForReporter(string $reporterId, int $perPage = 5, int $page = 1): ReportPage;

    /** @return list<string> */
    public function openEntityIds(string $entityType): array;

    /** @return list<string> */
    public function reporterReportIds(string $reporterId): array;

    /** @return list<string> */
    public function handlerReportIds(string $handlerId): array;
}
