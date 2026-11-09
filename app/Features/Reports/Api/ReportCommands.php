<?php

declare(strict_types=1);

namespace App\Features\Reports\Api;

interface ReportCommands
{
    public function submit(SubmitReportInput $input): ReportSnapshot;

    public function save(SaveReportInput $input): ReportSnapshot;

    public function triage(TriageReportInput $input): ReportSnapshot;

    public function delete(string $reportId): void;

    public function detachReporter(string $reporterId): void;

    public function detachHandler(string $handlerId): void;

    /** @param list<string> $reportIds */
    public function restoreReporterLinks(string $reporterId, array $reportIds): void;

    /** @param list<string> $reportIds */
    public function restoreHandlerLinks(string $handlerId, array $reportIds): void;
}
