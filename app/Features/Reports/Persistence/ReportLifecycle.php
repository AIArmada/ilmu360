<?php

declare(strict_types=1);

namespace App\Features\Reports\Persistence;

use App\Platform\Reporting\ReportLifecycle as ReportLifecycleContract;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

final class ReportLifecycle implements ReportLifecycleContract
{
    private const string STATUS_OPEN = 'open';

    private const string STATUS_TRIAGED = 'triaged';

    private const string STATUS_RESOLVED = 'resolved';

    private const string STATUS_DISMISSED = 'dismissed';

    /**
     * Set the initial open state before persistence.
     */
    public function initialize(Model $report, string $status): void
    {
        if ($report->exists) {
            throw new LogicException('An existing report must use transitionStatus().');
        }

        if ($status !== self::STATUS_OPEN) {
            throw new InvalidArgumentException('A new report must start in the open status.');
        }

        $now = CarbonImmutable::now();

        $report->setAttribute('status', $status);
        $this->applyStatusTimestamp($report, $status, $now, true);
        $this->initializeLastStateChangeAt($report, $now);
    }

    /**
     * Validate and timestamp a caller-provided state before the first save.
     */
    public function preparePersistedStatus(Model $report, string $status): void
    {
        $this->assertValidStatus($status);

        $now = CarbonImmutable::now();

        $this->applyStatusTimestamp($report, $status, $now);
        $this->initializeLastStateChangeAt($report, $now);
    }

    /**
     * Transition the report and record the matching lifecycle timestamp.
     */
    public function transition(Model $report, string $status): void
    {
        if (! $report->exists) {
            throw new LogicException('A new report must use initializeStatus().');
        }

        $this->assertValidStatus($status);
        $currentStatus = $this->normalizeStatus($report->getAttribute('status'));

        if ($currentStatus === null) {
            throw new LogicException('A persisted report must have a valid status before it can transition.');
        }

        if ($currentStatus === $status) {
            $now = CarbonImmutable::now();
            $this->applyStatusTimestamp($report, $status, $now);
            $this->initializeLastStateChangeAt($report, $now);

            if ($report->isDirty()) {
                $this->persist($report);
            }

            return;
        }

        $now = CarbonImmutable::now();
        $report->setAttribute('status', $status);
        $this->applyStatusTimestamp($report, $status, $now, true);
        $report->setAttribute('last_state_change_at', $now);
        $this->persist($report);
    }

    public function persist(Model $report): void
    {
        $report->save();
    }

    public function delete(Model $report): void
    {
        $report->delete();
    }

    public function detachReporter(Model $report, string $reporterId): void
    {
        $report->newQuery()->where('reporter_id', $reporterId)->update(['reporter_id' => null]);
    }

    public function detachHandler(Model $report, string $handlerId): void
    {
        $report->newQuery()->where('handled_by', $handlerId)->update(['handled_by' => null]);
    }

    /**
     * @param  list<string>  $reportIds
     */
    public function restoreReporterLinks(Model $report, string $reporterId, array $reportIds): void
    {
        if ($reportIds === []) {
            return;
        }

        $report->newQuery()
            ->whereKey($reportIds)
            ->whereNull('reporter_id')
            ->update(['reporter_id' => $reporterId]);
    }

    /**
     * @param  list<string>  $reportIds
     */
    public function restoreHandlerLinks(Model $report, string $handlerId, array $reportIds): void
    {
        if ($reportIds === []) {
            return;
        }

        $report->newQuery()
            ->whereKey($reportIds)
            ->whereNull('handled_by')
            ->update(['handled_by' => $handlerId]);
    }

    private function initializeLastStateChangeAt(Model $report, CarbonImmutable $now): void
    {
        if ($report->getAttribute('last_state_change_at') === null) {
            $report->setAttribute('last_state_change_at', $now);
        }
    }

    private function assertValidStatus(string $status): void
    {
        if (! in_array($status, $this->validStatuses(), true)) {
            throw new InvalidArgumentException(sprintf('The report status [%s] is invalid.', $status));
        }
    }

    /**
     * @return list<string>
     */
    private function validStatuses(): array
    {
        return [
            self::STATUS_OPEN,
            self::STATUS_TRIAGED,
            self::STATUS_RESOLVED,
            self::STATUS_DISMISSED,
        ];
    }

    private function normalizeStatus(mixed $status): ?string
    {
        return is_string($status) && in_array($status, $this->validStatuses(), true) ? $status : null;
    }

    private function applyStatusTimestamp(Model $report, string $status, CarbonImmutable $at, bool $overwrite = false): void
    {
        $attribute = match ($status) {
            self::STATUS_OPEN => 'reported_at',
            self::STATUS_TRIAGED => 'reviewed_at',
            self::STATUS_RESOLVED => 'resolved_at',
            self::STATUS_DISMISSED => 'rejected_at',
            default => null,
        };

        if ($attribute === null) {
            return;
        }

        if ($overwrite || $report->getAttribute($attribute) === null) {
            $report->setAttribute($attribute, $at);
        }
    }
}
