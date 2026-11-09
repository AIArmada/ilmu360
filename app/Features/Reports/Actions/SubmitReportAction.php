<?php

declare(strict_types=1);

namespace App\Features\Reports\Actions;

use App\Features\Reports\Models\Report;
use App\Platform\Media\MediaCollectionSync;
use App\Platform\Media\MediaEvidence;
use App\Platform\Reporting\ReportLifecycle;
use App\Shared\Auth\AccountActor;
use App\Shared\Reports\ReportEscalation;
use App\Shared\Reports\ReportSubject;
use App\Shared\Reports\ReportSubjectType;
use App\Shared\Signals\SignalRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class SubmitReportAction
{
    use AsAction;

    public function __construct(
        private readonly ReportEscalation $reportEscalation,
        private readonly SignalRecorder $signals,
        private readonly MediaCollectionSync $mediaCollectionSync,
        private readonly ReportLifecycle $lifecycle,
    ) {}

    /**
     * @param  array<int, UploadedFile>|null  $evidence
     */
    public function handle(
        ReportSubject $subject,
        ?AccountActor $reporter,
        string $reporterFingerprint,
        string $category,
        ?string $description = null,
        ?Request $request = null,
        ?array $evidence = null,
    ): Report {
        $existingReport = Report::query()
            ->where('entity_type', $subject->type->value)
            ->where('entity_id', $subject->id)
            ->where('reporter_fingerprint', $reporterFingerprint)
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if ($existingReport) {
            throw new RuntimeException('duplicate_report');
        }

        $report = new Report;
        $report->fill([
            'entity_type' => $subject->type->value,
            'entity_id' => $subject->id,
            'category' => $category,
            'description' => $description,
            'reporter_id' => $reporter?->actorId(),
            'reporter_fingerprint' => $reporterFingerprint,
        ]);

        $this->lifecycle->persist($report);

        $signalRequest = $request ?? request();
        $signalUser = $signalRequest->user();

        $this->signals->record(
            eventName: 'report.submitted',
            eventCategory: 'moderation',
            actorId: $signalUser instanceof AccountActor ? $signalUser->actorId() : null,
            properties: [
                'report_id' => (string) $report->getKey(),
                'entity_type' => (string) $report->entity_type,
                'entity_id' => (string) $report->entity_id,
                'category' => (string) $report->category,
                'status' => (string) $report->getAttribute('status'),
            ],
            request: $signalRequest,
            anonymousId: (string) $report->reporter_fingerprint,
        );

        if (in_array($category, ['donation_scam', 'fake_person', 'fake_institution', 'fake_reference'], true)) {
            $this->handleHighRiskReport($subject, $report);
        }

        $this->checkEscalationThreshold($subject);

        $this->mediaCollectionSync->syncMultiple($report, $evidence, MediaEvidence::COLLECTION);

        return $report;
    }

    private function handleHighRiskReport(ReportSubject $subject, Report $report): void
    {
        if ($subject->type !== ReportSubjectType::Event) {
            return;
        }

        $this->reportEscalation->remoderateEvent($subject->id, 'High-risk report: '.$report->category);
    }

    private function checkEscalationThreshold(ReportSubject $subject): void
    {
        $recentReportCount = Report::query()
            ->where('entity_type', $subject->type->value)
            ->where('entity_id', $subject->id)
            ->where('created_at', '>=', now()->subDay())
            ->whereNotNull('reporter_fingerprint')
            ->distinct()
            ->count('reporter_fingerprint');

        if ($recentReportCount < 2 || $subject->type !== ReportSubjectType::Event) {
            return;
        }

        $this->reportEscalation->remoderateEvent($subject->id, 'Escalated due to multiple reports within 24 hours.');
    }
}
