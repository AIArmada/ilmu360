<?php

declare(strict_types=1);

namespace App\Features\Reports\Queries;

use App\Features\Reports\Actions\ResolveReportCategoryOptionsAction;
use App\Features\Reports\Api\ReportPage;
use App\Features\Reports\Api\ReportReader;
use App\Features\Reports\Api\ReportSnapshot;
use App\Features\Reports\Api\ReportSubjectResolver;
use App\Features\Reports\Models\Report;
use App\Features\Reports\Support\ReportPresenter;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final readonly class DatabaseReportReader implements ReportReader
{
    public function __construct(
        private ReportSubjectResolver $subjects,
        private ResolveReportCategoryOptionsAction $categories,
    ) {}

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

    public function pageForReporter(
        string $reporterId,
        int $perPage = 5,
        int $page = 1,
        ?string $status = null,
        ?string $search = null,
    ): ReportPage {
        $perPage = max($perPage, 1);
        $page = max($page, 1);
        $query = $this->reporterQuery($reporterId);
        $this->applyStatusFilter($query, $status);
        $this->applySearch($query, $search);
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

    public function countsByStatusForReporter(string $reporterId): array
    {
        $rows = Report::query()
            ->where('reporter_id', $reporterId)
            ->select('status')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->getAttribute('status')] = (int) $row->getAttribute('aggregate');
        }

        return $counts;
    }

    /**
     * @param  Builder<Report>  $query
     */
    private function applyStatusFilter(Builder $query, ?string $status): void
    {
        $normalizedStatus = $status !== null ? trim($status) : '';

        if ($normalizedStatus === '' || $normalizedStatus === 'all') {
            return;
        }

        if (in_array($normalizedStatus, [
            Report::STATUS_OPEN,
            Report::STATUS_TRIAGED,
            Report::STATUS_RESOLVED,
            Report::STATUS_DISMISSED,
        ], true)) {
            $query->where('status', $normalizedStatus);
        }
    }

    /**
     * @param  Builder<Report>  $query
     */
    private function applySearch(Builder $query, ?string $search): void
    {
        $term = trim((string) $search);

        if ($term === '') {
            return;
        }

        $pattern = "%{$term}%";

        $query->where(function (Builder $searchQuery) use ($pattern): void {
            $searchQuery
                ->whereLike('message', $pattern)
                ->orWhereLike('report_type', $pattern)
                ->orWhereLike('title', $pattern);

            $columns = $this->subjectTitleColumns();

            if ($columns === []) {
                return;
            }

            $searchQuery->orWhereHasMorph(
                'entity',
                array_keys($columns),
                function (Builder $entityQuery, string $type) use ($columns, $pattern): void {
                    $entityQuery->where(function (Builder $titleQuery) use ($columns, $type, $pattern): void {
                        foreach ($columns[$type] ?? [] as $column) {
                            $titleQuery->orWhereLike($column, $pattern);
                        }
                    });
                },
            );
        });
    }

    /**
     * Searchable title columns for the morph aliases reporters can target.
     *
     * @return array<class-string, list<string>>
     */
    private function subjectTitleColumns(): array
    {
        $aliases = [
            'institution' => ['name'],
            'person' => ['name'],
            'reference' => ['title'],
            'event' => ['title'],
        ];

        $columns = [];

        foreach ($aliases as $alias => $titleColumns) {
            $modelClass = MorphModelResolver::tryResolve($alias);

            if ($modelClass !== null) {
                $columns[$modelClass] = $titleColumns;
            }
        }

        return $columns;
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
        $entityType = (string) $report->entity_type;
        $category = (string) $report->category;

        return new ReportSnapshot(
            id: (string) $report->getKey(),
            entityType: $entityType,
            entityId: (string) $report->entity_id,
            category: $category,
            description: $report->description,
            status: (string) $report->getAttribute('status'),
            resolutionNote: $report->resolution_note,
            reporterId: $report->reporter_id !== null ? (string) $report->reporter_id : null,
            handledBy: $report->handled_by !== null ? (string) $report->handled_by : null,
            reportedAt: $report->reported_at,
            createdAt: $report->created_at?->toImmutable(),
            entityTitle: ReportPresenter::entityTitle($report),
            entityLabel: $this->subjects->label($entityType),
            categoryLabel: $this->categories->handle($entityType)[$category] ?? Str::headline($category),
        );
    }
}
