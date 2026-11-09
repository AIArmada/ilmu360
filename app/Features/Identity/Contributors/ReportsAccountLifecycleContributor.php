<?php

declare(strict_types=1);

namespace App\Features\Identity\Contributors;

use App\Features\Reports\Api\ReportCommands;
use App\Features\Reports\Api\ReportReader;
use App\Shared\Auth\AccountLifecycleContributor;

/**
 * Preserves the report attribution links owned by the account lifecycle.
 *
 * This replaces the report-specific steps that previously lived inline in
 * HasUserRestoration without changing the Reports feature API.
 */
final readonly class ReportsAccountLifecycleContributor implements AccountLifecycleContributor
{
    public function __construct(
        private ReportReader $reports,
        private ReportCommands $commands,
    ) {}

    public function key(): string
    {
        return 'reports';
    }

    public function priority(): int
    {
        return 200;
    }

    public function snapshot(string $accountId): array
    {
        return [
            'report_ids' => $this->reports->reporterReportIds($accountId),
            'handled_report_ids' => $this->reports->handlerReportIds($accountId),
        ];
    }

    public function detach(string $accountId): void
    {
        $this->commands->detachReporter($accountId);
        $this->commands->detachHandler($accountId);
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $this->commands->restoreReporterLinks(
            $accountId,
            $this->stringIds($snapshot['report_ids'] ?? []),
        );
        $this->commands->restoreHandlerLinks(
            $accountId,
            $this->stringIds($snapshot['handled_report_ids'] ?? []),
        );
    }

    public function afterRestore(string $accountId): void {}

    /**
     * @return list<string>
     */
    private function stringIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(static fn (mixed $id): bool => is_string($id) || is_int($id))
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }
}
