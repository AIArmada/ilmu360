<?php

declare(strict_types=1);

namespace App\Features\Reports\Services;

use App\Features\Reports\Actions\SaveReportAction;
use App\Features\Reports\Actions\SubmitReportAction;
use App\Features\Reports\Actions\TriageReportAction;
use App\Features\Reports\Api\ReportCommands as ReportCommandsContract;
use App\Features\Reports\Api\ReportReader;
use App\Features\Reports\Api\ReportSnapshot;
use App\Features\Reports\Api\SaveReportInput;
use App\Features\Reports\Api\SubmitReportInput;
use App\Features\Reports\Api\TriageReportInput;
use App\Features\Reports\Models\Report;
use App\Platform\Reporting\ReportLifecycle;
use App\Shared\Reports\ReportSubject;
use App\Shared\Reports\ReportSubjectResolver;
use RuntimeException;

final readonly class ReportCommands implements ReportCommandsContract
{
    public function __construct(
        private ReportReader $reader,
        private ReportLifecycle $lifecycle,
        private ReportSubjectResolver $reportSubjects,
        private SaveReportAction $saveReportAction,
        private SubmitReportAction $submitReportAction,
        private TriageReportAction $triageReportAction,
    ) {}

    public function submit(SubmitReportInput $input): ReportSnapshot
    {
        $subject = $this->reportSubjects->find($input->entityType, $input->entityId);

        if (! $subject instanceof ReportSubject) {
            throw new RuntimeException('report_subject_not_found');
        }

        $report = $this->submitReportAction->handle(
            $subject,
            $input->reporter,
            $input->reporterFingerprint,
            $input->category,
            $input->description,
            null,
            $input->evidence,
        );

        return $this->snapshot($report);
    }

    public function save(SaveReportInput $input): ReportSnapshot
    {
        $report = $input->reportId !== null
            ? Report::query()->findOrFail($input->reportId)
            : null;

        $saved = $this->saveReportAction->handle($input->attributes, $report);

        return $this->snapshot($saved);
    }

    public function triage(TriageReportInput $input): ReportSnapshot
    {
        $report = $this->triageReportAction->handle(
            $input->reportId,
            $input->action,
            $input->resolutionNote,
            $input->actor,
        );

        return $this->snapshot($report);
    }

    public function delete(string $reportId): void
    {
        $report = Report::query()->find($reportId);

        if ($report instanceof Report) {
            $this->lifecycle->delete($report);
        }
    }

    public function detachReporter(string $reporterId): void
    {
        $this->lifecycle->detachReporter(new Report, $reporterId);
    }

    public function detachHandler(string $handlerId): void
    {
        $this->lifecycle->detachHandler(new Report, $handlerId);
    }

    public function restoreReporterLinks(string $reporterId, array $reportIds): void
    {
        $this->lifecycle->restoreReporterLinks(new Report, $reporterId, $reportIds);
    }

    public function restoreHandlerLinks(string $handlerId, array $reportIds): void
    {
        $this->lifecycle->restoreHandlerLinks(new Report, $handlerId, $reportIds);
    }

    private function snapshot(Report $report): ReportSnapshot
    {
        return $this->reader->find((string) $report->getKey())
            ?? throw new RuntimeException('Saved report could not be reloaded.');
    }
}
