<?php

declare(strict_types=1);

namespace App\Features\Reports\Actions;

use App\Features\Reports\Api\ReportTriageContract;
use App\Features\Reports\Models\Report;
use App\Features\Reports\Notifications\ReportResolvedNotification;
use App\Shared\Auth\AccountActor;
use App\Shared\Auth\AccountDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final readonly class TriageReportAction
{
    use AsAction;

    public function __construct(
        private AccountDirectory $accounts,
        private ReportTriageContract $triage,
    ) {}

    public function handle(string $reportId, string $action, ?string $resolutionNote, AccountActor $actor): Report
    {
        $resolutionNote = is_string($resolutionNote) && trim($resolutionNote) !== ''
            ? trim($resolutionNote)
            : null;

        $report = DB::transaction(function () use ($action, $reportId, $resolutionNote, $actor): Report {
            $targetStatus = match ($action) {
                'triage' => Report::STATUS_TRIAGED,
                'resolve' => Report::STATUS_RESOLVED,
                'dismiss' => Report::STATUS_DISMISSED,
                'reopen' => Report::STATUS_OPEN,
                default => throw new InvalidArgumentException('Unsupported report triage action.'),
            };

            $report = Report::query()->whereKey($reportId)->lockForUpdate()->firstOrFail();

            if (! array_key_exists($action, $this->triage->availableActions((string) $report->status))) {
                throw ValidationException::withMessages([
                    'status' => __('This report is no longer in a status that allows this action. Please refresh.'),
                ]);
            }

            $report->fill([
                'handled_by' => $action === 'reopen' ? null : $actor->actorId(),
                'resolution_note' => $action === 'reopen' ? null : $resolutionNote,
            ]);
            $report->transitionStatus($targetStatus);

            return $report->fresh(['entity', 'reporter', 'handler', 'media']) ?? $report;
        });

        $this->notifyReporter($report, $action);

        return $report;
    }

    private function notifyReporter(Report $report, string $action): void
    {
        if (! in_array($action, ['resolve', 'dismiss'], true)) {
            return;
        }

        try {
            $reporterId = $report->reporter_id;
            $reporter = $reporterId !== null ? $this->accounts->resolveNotifiable((string) $reporterId) : null;

            $reporter?->notify(new ReportResolvedNotification($report));
        } catch (Throwable $exception) {
            Log::warning('Unable to send report resolution notification.', [
                'report_id' => (string) $report->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
