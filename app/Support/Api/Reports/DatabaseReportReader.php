<?php

declare(strict_types=1);

namespace App\Support\Api\Reports;

use App\Features\Reports\Api\ReportPage;
use App\Features\Reports\Api\ReportReader;
use App\Features\Reports\Api\ReportSnapshot;
use App\Filament\Resources\Reports\Support\ReportPresenter;
use App\Models\Report;
use Illuminate\Database\Eloquent\Builder;

final class DatabaseReportReader implements ReportReader
{
    public function find(string $reportId): ?ReportSnapshot
    {
        $report = $this->query()->find($reportId);

        return $report instanceof Report ? $this->snapshot($report) : null;
    }

    public function forReporter(string $reporterId, ?int $limit = null): array
    {
        $query = $this->reporterQuery($reporterId)->latest('created_at');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()
            ->map(fn (Report $report): ReportSnapshot => $this->snapshot($report))
            ->all();
    }

    public function pageForReporter(string $reporterId, int $perPage = 5, int $page = 1): ReportPage
    {
        $perPage = max($perPage, 1);
        $page = max($page, 1);
        $query = $this->reporterQuery($reporterId);
        $total = (clone $query)->count();

        $items = $query->latest('created_at')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (Report $report): ReportSnapshot => $this->snapshot($report))
            ->all();

        $lastPage = max((int) ceil($total / $perPage), 1);

        return new ReportPage(
            items: $items,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            lastPage: $lastPage,
        );
    }

    public function openEntityIds(string $entityType): array
    {
        return Report::query()
            ->where('status', Report::STATUS_OPEN)
            ->where('entity_type', $entityType)
            ->distinct()
            ->pluck('entity_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function reporterReportIds(string $reporterId): array
    {
        return Report::query()
            ->where('reporter_id', $reporterId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function handlerReportIds(string $handlerId): array
    {
        return Report::query()
            ->where('handled_by', $handlerId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @return Builder<Report>
     */
    private function query(): Builder
    {
        return Report::query()->with(['entity']);
    }

    /**
     * @return Builder<Report>
     */
    private function reporterQuery(string $reporterId): Builder
    {
        return $this->query()->where('reporter_id', $reporterId);
    }

    private function snapshot(Report $report): ReportSnapshot
    {
        return new ReportSnapshot(
            id: (string) $report->getKey(),
            entityType: (string) $report->entity_type,
            entityId: (string) $report->entity_id,
            category: (string) $report->category,
            description: $report->description,
            status: (string) $report->getAttribute('status'),
            resolutionNote: $report->resolution_note,
            reporterId: $report->reporter_id !== null ? (string) $report->reporter_id : null,
            handledBy: $report->handled_by !== null ? (string) $report->handled_by : null,
            reportedAt: $report->reported_at,
            createdAt: $report->created_at?->toImmutable(),
            entityTitle: ReportPresenter::entityTitle($report),
        );
    }
}
