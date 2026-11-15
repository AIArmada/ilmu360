<?php

declare(strict_types=1);

namespace App\Features\Reports\Contracts;

use App\Features\Reports\Models\Report;

interface ReportLifecycle
{
    public function initialize(Report $report, string $status): void;

    public function preparePersistedStatus(Report $report, string $status): void;

    public function transition(Report $report, string $status): void;

    public function persist(Report $report): void;

    public function delete(Report $report): void;

    public function detachReporter(Report $report, string $reporterId): void;

    public function detachHandler(Report $report, string $handlerId): void;

    /**
     * @param  list<string>  $reportIds
     */
    public function restoreReporterLinks(Report $report, string $reporterId, array $reportIds): void;

    /**
     * @param  list<string>  $reportIds
     */
    public function restoreHandlerLinks(Report $report, string $handlerId, array $reportIds): void;
}
