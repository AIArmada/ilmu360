<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Actions\BrowserSelection;

use App\Features\ShareTracking\Api\BrowserSelectionWriter;
use App\Features\ShareTracking\Support\ShareTrackingModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Owns every mutation of the browser share selection. The persisted selection
 * is attribution authority; the cookie only transports the browser key.
 *
 * Callers run these methods inside their surrounding transaction so the fixed
 * lock order (selection, attribution, link, affiliate) and the expiry and
 * eligibility checks that follow stay unchanged.
 */
final readonly class LockBrowserSelectionAction implements BrowserSelectionWriter
{
    public function lockOrCreate(string $browserKey): string
    {
        return (string) $this->lockOrCreateSelection($browserKey)->getKey();
    }

    public function lockExisting(string $browserKey): ?string
    {
        $selection = $this->lockExistingSelection($browserKey);

        return $selection instanceof Model ? (string) $selection->getKey() : null;
    }

    public function currentAttributionId(string $selectionId): ?string
    {
        $attributionId = $this->selectionById($selectionId, lock: false)?->getAttribute('current_attribution_id');

        return is_string($attributionId) && $attributionId !== '' ? $attributionId : null;
    }

    public function accept(string $selectionId, string $attributionId): void
    {
        $selection = $this->selectionById($selectionId, lock: true);

        if (! $selection instanceof Model) {
            return;
        }

        $selection->forceFill([
            'current_attribution_id' => $attributionId,
            'accepted_at' => now(),
        ])->save();
    }

    public function clear(?string $selectionId = null, ?string $browserKey = null): void
    {
        $selection = $selectionId !== null ? $this->selectionById($selectionId, lock: false) : null;

        if (! $selection instanceof Model && is_string($browserKey) && $browserKey !== '') {
            $selection = ShareTrackingModel::browserSelectionQuery()
                ->where('browser_key', $browserKey)
                ->first();
        }

        if ($selection instanceof Model) {
            $selection->forceFill([
                'current_attribution_id' => null,
                'accepted_at' => null,
            ])->save();
        }
    }

    private function lockOrCreateSelection(string $browserKey): Model
    {
        $selection = $this->lockExistingSelection($browserKey);

        if ($selection instanceof Model) {
            return $selection;
        }

        try {
            return DB::transaction(static fn (): Model => ShareTrackingModel::browserSelectionQuery()->create([
                'browser_key' => $browserKey,
                'current_attribution_id' => null,
                'accepted_at' => null,
            ]), 1);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return ShareTrackingModel::browserSelectionQuery()
                ->where('browser_key', $browserKey)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }

    private function lockExistingSelection(string $browserKey): ?Model
    {
        return ShareTrackingModel::browserSelectionQuery()
            ->where('browser_key', $browserKey)
            ->lockForUpdate()
            ->first();
    }

    private function selectionById(string $selectionId, bool $lock): ?Model
    {
        $query = ShareTrackingModel::browserSelectionQuery()->whereKey($selectionId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? $exception->getCode()), ['23000', '23505'], true);
    }
}
