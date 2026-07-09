<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Centralises status → timestamp mapping for lifecycle transitions.
 *
 * When a model transitions to status `Y`, sets `y_at = now()` when that
 * attribute is fillable (or already present), and bumps `last_state_change_at`
 * when fillable.
 */
trait RecordsLifecycleTimestamps
{
    /**
     * @param  array<string, string>  $statusToTimestamp  Map of status value => column name
     *                                                    (e.g. ['approved' => 'approved_at']).
     *                                                    Defaults to "{status}_at".
     * @param  list<string>  $clearTimestamps  Timestamp columns to null out on this transition
     */
    public function recordLifecycleTransition(
        string $status,
        ?Carbon $at = null,
        array $statusToTimestamp = [],
        array $clearTimestamps = [],
    ): void {
        /** @var Model $this */
        $at ??= now();

        foreach ($clearTimestamps as $column) {
            if ($this->canWriteLifecycleColumn($column)) {
                $this->setAttribute($column, null);
            }
        }

        $column = $statusToTimestamp[$status] ?? (Str::snake($status).'_at');

        if ($this->canWriteLifecycleColumn($column)) {
            $this->setAttribute($column, $at);
        }

        if ($this->canWriteLifecycleColumn('last_state_change_at')) {
            $this->setAttribute('last_state_change_at', $at);
        }
    }

    protected function canWriteLifecycleColumn(string $column): bool
    {
        /** @var Model $this */
        return in_array($column, $this->getFillable(), true)
            || array_key_exists($column, $this->getAttributes());
    }
}
