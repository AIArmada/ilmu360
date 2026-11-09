<?php

declare(strict_types=1);

namespace App\Support\Api\Reports;

use App\Actions\Reports\SaveReportAction;
use App\Actions\Reports\SubmitReportAction;
use App\Features\Reports\Api\ReportCommands as ReportCommandsContract;
use App\Features\Reports\Api\ReportReader;
use App\Features\Reports\Api\ReportSnapshot;
use App\Features\Reports\Api\SaveReportInput;
use App\Features\Reports\Api\SubmitReportInput;
use App\Features\Reports\Api\TriageReportInput;
use App\Models\Report;
use App\Models\User;
use App\Notifications\ReportResolvedNotification;
use App\Platform\Reporting\ReportLifecycle;
use App\Shared\Reports\ReportSubject;
use App\Shared\Reports\ReportSubjectResolver;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class ReportCommands implements ReportCommandsContract
{
    public function __construct(
        private ReportReader $reader,
        private ReportLifecycle $lifecycle,
        private ReportSubjectResolver $reportSubjects,
        private SaveReportAction $saveReportAction,
        private SubmitReportAction $submitReportAction,
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
        $report = Report::query()->findOrFail($input->reportId);
        $resolutionNote = is_string($input->resolutionNote) && trim($input->resolutionNote) !== ''
            ? trim($input->resolutionNote)
            : null;

        $report = DB::transaction(function () use ($input, $report, $resolutionNote): Report {
            $targetStatus = match ($input->action) {
                'triage' => Report::STATUS_TRIAGED,
                'resolve' => Report::STATUS_RESOLVED,
                'dismiss' => Report::STATUS_DISMISSED,
                'reopen' => Report::STATUS_OPEN,
                default => throw new \InvalidArgumentException('Unsupported report triage action.'),
            };

            $report->fill([
                'handled_by' => $input->action === 'reopen' ? null : $input->actor->actorId(),
                'resolution_note' => $input->action === 'reopen' ? null : $resolutionNote,
            ]);
            $report->transitionStatus($targetStatus);

            $report->loadMissing(['reporter', 'entity']);

            if (in_array($input->action, ['resolve', 'dismiss'], true) && $report->reporter instanceof User) {
                $report->reporter->notify(new ReportResolvedNotification($report));
            }

            return $report->fresh(['entity', 'reporter', 'handler']) ?? $report;
        });

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
