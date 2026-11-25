<?php

declare(strict_types=1);

namespace App\Features\Feedback\Queries;

use App\Features\Feedback\Api\AppFeedbackPage;
use App\Features\Feedback\Api\AppFeedbackReader;
use App\Features\Feedback\Api\AppFeedbackSnapshot;
use App\Features\Feedback\Enums\AppFeedbackStatus;
use App\Features\Feedback\Models\AppFeedback;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final readonly class EloquentAppFeedbackReader implements AppFeedbackReader
{
    public function pageForReporter(
        string $reporterId,
        int $perPage = 8,
        int $page = 1,
        ?string $status = null,
        ?string $search = null,
    ): AppFeedbackPage {
        $perPage = max($perPage, 1);
        $page = max($page, 1);
        $query = $this->queryForReporter($reporterId, $status, $search, withCount: true);
        $total = (clone $query)->count();

        /** @var list<AppFeedbackSnapshot> $items */
        $items = $query->latest('created_at')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (AppFeedback $feedback): AppFeedbackSnapshot => $this->snapshot($feedback))
            ->values()
            ->all();

        return new AppFeedbackPage(
            items: $items,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            lastPage: max((int) ceil($total / $perPage), 1),
        );
    }

    public function recentForReporter(string $reporterId, int $limit = 5): array
    {
        /** @var list<AppFeedbackSnapshot> $items */
        $items = $this->queryForReporter($reporterId, null, null, withCount: true)
            ->latest('created_at')
            ->limit(max($limit, 1))
            ->get()
            ->map(fn (AppFeedback $feedback): AppFeedbackSnapshot => $this->snapshot($feedback))
            ->values()
            ->all();

        return $items;
    }

    public function findForReporter(string $reporterId, string $feedbackId): ?AppFeedbackSnapshot
    {
        if (! Str::isUuid($feedbackId)) {
            return null;
        }

        $feedback = AppFeedback::query()
            ->where('reporter_id', $reporterId)
            ->whereKey($feedbackId)
            ->withCount('visibleMessages')
            ->first();

        return $feedback instanceof AppFeedback ? $this->snapshot($feedback) : null;
    }

    public function countsByStatusForReporter(string $reporterId): array
    {
        $rows = AppFeedback::query()
            ->where('reporter_id', $reporterId)
            ->select('status')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $status = $row->getAttribute('status');
            $value = $status instanceof AppFeedbackStatus ? $status->value : (string) $status;
            $counts[$value] = (int) $row->getAttribute('aggregate');
        }

        return $counts;
    }

    /**
     * @return Builder<AppFeedback>
     */
    private function queryForReporter(
        string $reporterId,
        ?string $status,
        ?string $search,
        bool $withCount = false,
    ): Builder {
        $query = AppFeedback::query()->where('reporter_id', $reporterId);

        if ($withCount) {
            $query->withCount('visibleMessages');
        }

        $normalizedStatus = $status !== null ? trim($status) : '';

        if ($normalizedStatus !== '' && $normalizedStatus !== 'all') {
            $resolvedStatus = AppFeedbackStatus::tryFrom($normalizedStatus);

            if ($resolvedStatus instanceof AppFeedbackStatus) {
                $query->where('status', $resolvedStatus);
            }
        }

        $term = trim((string) $search);

        if ($term !== '') {
            $pattern = "%{$term}%";
            $query->where(function (Builder $searchQuery) use ($pattern): void {
                $searchQuery->whereLike('title', $pattern)->orWhereLike('description', $pattern);
            });
        }

        return $query;
    }

    private function snapshot(AppFeedback $feedback): AppFeedbackSnapshot
    {
        $id = (string) $feedback->getKey();

        return new AppFeedbackSnapshot(
            id: $id,
            category: $feedback->category->value,
            categoryLabel: $feedback->category->getLabel(),
            categoryColor: $feedback->category->getColor(),
            status: $feedback->status->value,
            statusLabel: $feedback->status->getLabel(),
            statusColor: $feedback->status->getColor(),
            title: $feedback->title,
            description: $feedback->description,
            pageUrl: $feedback->page_url,
            resolutionNote: $feedback->resolution_note,
            visibleMessagesCount: (int) $feedback->getAttribute('visible_messages_count'),
            createdAt: $feedback->created_at->toImmutable(),
            threadUrl: route('app-feedback.index', ['lihat' => $id]).'#maklum-balas-'.$id,
        );
    }
}
