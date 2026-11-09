<?php

declare(strict_types=1);

namespace App\Platform\Reporting;

use Illuminate\Database\Eloquent\Model;

interface ReportLifecycle
{
    public function initialize(Model $report, string $status): void;

    public function preparePersistedStatus(Model $report, string $status): void;

    public function transition(Model $report, string $status): void;

    public function persist(Model $report): void;

    public function delete(Model $report): void;

    public function detachReporter(Model $report, string $reporterId): void;

    public function detachHandler(Model $report, string $handlerId): void;

    /**
     * @param  list<string>  $reportIds
     */
    public function restoreReporterLinks(Model $report, string $reporterId, array $reportIds): void;

    /**
     * @param  list<string>  $reportIds
     */
    public function restoreHandlerLinks(Model $report, string $handlerId, array $reportIds): void;
}
