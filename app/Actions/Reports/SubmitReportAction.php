<?php

namespace App\Actions\Reports;

use App\Models\Event;
use App\Models\Report;
use App\Models\User;
use App\Services\ModerationService;
use App\Services\Signals\ProductSignalsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class SubmitReportAction
{
    use AsAction;

    public function __construct(
        private readonly ModerationService $moderationService,
        private readonly ProductSignalsService $productSignalsService,
    ) {}

    public function handle(
        Model $entity,
        string $entityType,
        ?User $reporter,
        string $reporterFingerprint,
        string $category,
        ?string $description = null,
        ?Request $request = null,
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
            'reporter_id' => $reporter?->getKey(),
            'reporter_fingerprint' => $reporterFingerprint,
            'status' => 'open',
        ]);

        $this->productSignalsService->recordReportSubmitted($report, $request ?? request());

        if (in_array($category, ['donation_scam', 'fake_speaker', 'fake_institution', 'fake_reference'], true)) {
            $this->handleHighRiskReport($entity, $report);
        }

        $this->checkEscalationThreshold($entityType, (string) $entity->getKey());

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
