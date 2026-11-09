<?php

declare(strict_types=1);

namespace App\Features\Reports\Actions;

use App\Features\Reports\Models\Report;
use App\Features\Reports\Notifications\ReportResolvedNotification;
use App\Shared\Auth\AccountActor;
use App\Shared\Auth\AccountDirectory;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class TriageReportAction
{
    use AsAction;

    public function __construct(
        private AccountDirectory $accounts,
    ) {}

    public function handle(string $reportId, string $action, ?string $resolutionNote, AccountActor $actor): Report
    {
        $report = Report::query()->findOrFail($reportId);
        $resolutionNote = is_string($resolutionNote) && trim($resolutionNote) !== ''
            ? trim($resolutionNote)
            : null;

        return DB::transaction(function () use ($action, $report, $resolutionNote, $actor): Report {
            $targetStatus = match ($action) {
                'triage' => Report::STATUS_TRIAGED,
                'resolve' => Report::STATUS_RESOLVED,
                'dismiss' => Report::STATUS_DISMISSED,
                'reopen' => Report::STATUS_OPEN,
                default => throw new InvalidArgumentException('Unsupported report triage action.'),
            };

            $report->fill([
                'handled_by' => $action === 'reopen' ? null : $actor->actorId(),
                'resolution_note' => $action === 'reopen' ? null : $resolutionNote,
            ]);
            $report->transitionStatus($targetStatus);

            $this->notifyReporter($report, $action);

            return $report->fresh(['entity', 'reporter', 'handler']) ?? $report;
        });
    }

    private function notifyReporter(Report $report, string $action): void
    {
        if (! in_array($action, ['resolve', 'dismiss'], true)) {
            return;
        }

        $reporterId = $report->reporter_id;
        $reporter = $reporterId !== null ? $this->accounts->resolveNotifiable((string) $reporterId) : null;

        $reporter?->notify(new ReportResolvedNotification($report));
    }
}
