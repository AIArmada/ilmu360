<?php

namespace App\Actions\Reports;

use App\Models\Event;
use App\Models\Report;
use App\Models\User;
use App\Platform\Media\MediaCollectionSync;
use App\Platform\Media\MediaEvidence;
use App\Services\ModerationService;
use App\Shared\Auth\AccountActor;
use App\Shared\Signals\SignalRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class SubmitReportAction
{
    use AsAction;

    public function __construct(
        private readonly ModerationService $moderationService,
        private readonly SignalRecorder $signals,
        private readonly MediaCollectionSync $mediaCollectionSync,
    ) {}

    /**
     * @param  array<int, UploadedFile>|null  $evidence
     */
    public function handle(
        Model $entity,
        string $entityType,
        ?AccountActor $reporter,
        string $reporterFingerprint,
        string $category,
        ?string $description = null,
        ?Request $request = null,
        ?array $evidence = null,
    ): Report {
        $existingReport = Report::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', (string) $entity->getKey())
            ->where('reporter_fingerprint', $reporterFingerprint)
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if ($existingReport) {
            throw new RuntimeException('duplicate_report');
        }

        $report = Report::create([
            'entity_type' => $entityType,
            'entity_id' => (string) $entity->getKey(),
            'category' => $category,
            'description' => $description,
            'reporter_id' => $reporter?->actorId(),
            'reporter_fingerprint' => $reporterFingerprint,
        ]);

        $signalRequest = $request ?? request();
        $signalUser = $signalRequest->user();

        $this->signals->record(
            eventName: 'report.submitted',
            eventCategory: 'moderation',
            actorId: $signalUser instanceof User ? $signalUser->getKey() : null,
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
            $this->handleHighRiskReport($entity, $report);
        }

        $this->checkEscalationThreshold($entityType, (string) $entity->getKey());

        $this->mediaCollectionSync->syncMultiple($report, $evidence, MediaEvidence::COLLECTION);

        return $report;
    }

    private function handleHighRiskReport(Model $entity, Report $report): void
    {
        if (! $entity instanceof Event) {
            return;
        }

        $this->queueEventForModeration($entity, 'High-risk report: '.$report->category);
    }

    private function checkEscalationThreshold(string $entityType, string $entityId): void
    {
        $recentReportCount = Report::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('created_at', '>=', now()->subDay())
            ->whereNotNull('reporter_fingerprint')
            ->distinct()
            ->count('reporter_fingerprint');

        if ($recentReportCount < 2 || $entityType !== 'event') {
            return;
        }

        $event = Event::query()->find($entityId);

        if (! $event instanceof Event) {
            return;
        }

        $this->queueEventForModeration($event, 'Escalated due to multiple reports within 24 hours.');
    }

    private function queueEventForModeration(Event $event, string $note): void
    {
        if ((string) $event->status !== 'approved') {
            return;
        }

        $this->moderationService->remoderate($event, null, $note);
    }
}
